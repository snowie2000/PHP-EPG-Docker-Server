<?php
/**
 * @file update.php
 * @brief 更新脚本
 * 
 * 该脚本用于定期从配置的 XML 源下载节目数据，并将其存入 SQLite 数据库中。
 * 
 * 作者: Tak
 * GitHub: https://github.com/TakcC/PHP-EPG-Docker-Server
 */

// 引入公共脚本
require_once 'public.php';

// 设置超时时间为20分钟
set_time_limit(20*60);

// 设置时间格式
define('TIME_FORMAT', "[y-m-d H:i:s]");

// 日志记录函数
function logMessage(&$log_messages, $message) {
    $log_messages[] = date(TIME_FORMAT) . " " . $message;
}

// 删除过期数据和日志
function deleteOldData($db, $keep_days, &$log_messages) {
    global $Config;

    // 删除 t.xml 和 t.xml.gz 文件
    @unlink('./t.xml');
    @unlink('./t.xml.gz');

    // 循环清理过期数据
    $threshold_date = date('Y-m-d', strtotime("-$keep_days days + 1 day"));
    $tables = [
        'epg_data' => ['date', '清理EPG数据'],
        'update_log' => ['timestamp', '清理更新日志'],
        'cron_log' => ['timestamp', '清理定时日志']
    ];
    foreach ($tables as $table => $values) {
        list($column, $logMessage) = $values;
        $stmt = $db->prepare("DELETE FROM $table WHERE $column < :threshold_date");
        $stmt->bindValue(':threshold_date', $threshold_date, PDO::PARAM_STR);
        $stmt->execute();
        logMessage($log_messages, "【{$logMessage}】 共 {$stmt->rowCount()} 条。");
    }
}

// 格式化时间函数
function getFormatTime($time) {
    return [
        'date' => substr($time, 0, 4) . '-' . substr($time, 4, 2) . '-' . substr($time, 6, 2),
        'time' => strlen($time) >= 12 ? substr($time, 8, 2) . ':' . substr($time, 10, 2) : ''
    ];
}

// 下载数据并存入数据库
function processData($xml_url, $db, &$log_messages, $gen_list) {
    $xml_data = downloadData($xml_url);
    if ($xml_data !== false && stripos($xml_data, 'not found') === false) {
        logMessage($log_messages, "【下载】 成功");
        if (strtoupper(substr($xml_url, -3)) === '.GZ') {
            $xml_data = gzdecode($xml_data);
            if ($xml_data === false) {
                logMessage($log_messages, ' 【解压缩失败！！！】');
                return;
            }
        }
        $db->beginTransaction();
        try {
            processXmlData($xml_data, date('Y-m-d'), $db, $gen_list);
            $db->commit();
            logMessage($log_messages, "【更新】 成功");
        } catch (Exception $e) {
            $db->rollBack();
            logMessage($log_messages, "【处理数据出错！！！】 " . $e->getMessage());
        }
    } else {
        logMessage($log_messages, "【下载】 失败！！！");
    }
}

function loadHashesFromJson($json_file) {
    if (file_exists($json_file)) {
        $json_data = file_get_contents($json_file);
        return json_decode($json_data, true);
    }
    return [];
}

function saveHashesToJson($json_file, $hashes) {
    $json_data = json_encode($hashes, JSON_PRETTY_PRINT);
    file_put_contents($json_file, $json_data);
}

// 获取限定频道列表及映射关系
function getGenList($db) {
    $channels = $db->query("SELECT channel FROM gen_list")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($channels)) {
        return [
            'gen_list_mapping' => [],
            'gen_list' => []
        ];
    }
    
    $channelsString = implode("\n", $channels);
    $channelsSimplified = t2s($channelsString);

    $gen_list_mapping = [];
    $cleanedChannels = [];

    foreach (explode("\n", $channelsSimplified) as $index => $simplifiedChannel) {
        $cleanedChannel = cleanChannelName($simplifiedChannel);
        $cleanedChannels[] = $cleanedChannel;
        
        // 如果该清理后的频道名称已经存在于映射中，则将原始频道名称追加到数组中
        if (!isset($gen_list_mapping[$cleanedChannel])) {
            $gen_list_mapping[$cleanedChannel] = [];
        }
        $gen_list_mapping[$cleanedChannel][] = $channels[$index];
    }
    
    $gen_list = array_unique($cleanedChannels);

    return [
        'gen_list_mapping' => $gen_list_mapping,
        'gen_list' => $gen_list
    ];
}

// 从 epg_data 表生成 XML 数据并逐个频道写入 t.xml 文件
function generateXmlFromEpgData($db, $include_future_only, $gen_list_mapping) {
    $currentDate = date('Y-m-d'); // 获取当前日期
    $dateCondition = $include_future_only ? "WHERE date >= '$currentDate'" : '';

    // 合并查询
    $query = "SELECT date, channel, epg_diyp FROM epg_data $dateCondition ORDER BY channel ASC, date ASC";
    $stmt = $db->query($query);

    $mappingIsEmpty = empty($gen_list_mapping); // 检查 $gen_list_mapping 是否为空

    // 创建 XMLWriter 实例
    $xmlWriter = new XMLWriter();
    $xmlWriter->openUri('t.xml');
    $xmlWriter->startDocument('1.0', 'UTF-8');
    $xmlWriter->startElement('tv');
    $xmlWriter->writeAttribute('info-name', 'by Tak');
    $xmlWriter->writeAttribute('info-url', 'https://github.com/TakcC/PHP-EPG-Docker-Server');
    $xmlWriter->setIndent(true);
    $xmlWriter->setIndentString('	'); // 设置缩进

    // 存储节目数据以按频道分组
    $channelData = [];

    while ($program = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $originalChannel = $program['channel'];
        if ($mappingIsEmpty || isset($gen_list_mapping[$originalChannel])) {
            $channelsToProcess = $mappingIsEmpty ? [$originalChannel] : $gen_list_mapping[$originalChannel];
            foreach ($channelsToProcess as $mappedChannel) {
                if (!isset($channelData[$mappedChannel])) {
                    $channelData[$mappedChannel] = [];
                }
                $channelData[$mappedChannel][] = $program;
            }
        }
    }

    // 逐个频道处理
    foreach ($channelData as $mappedChannel => $programs) {
        // 写入频道信息
        $xmlWriter->startElement('channel');
        $xmlWriter->writeAttribute('id', htmlspecialchars($mappedChannel, ENT_XML1, 'UTF-8'));
        $xmlWriter->startElement('display-name');
        $xmlWriter->writeAttribute('lang', 'zh');
        $xmlWriter->text(htmlspecialchars($mappedChannel, ENT_XML1, 'UTF-8'));
        $xmlWriter->endElement(); // display-name
        $xmlWriter->endElement(); // channel

        // 写入该频道的所有节目数据
        foreach ($programs as $program) {
            $data = json_decode($program['epg_diyp'], true);
            foreach ($data['epg_data'] as $item) {
                $xmlWriter->startElement('programme');
                $xmlWriter->writeAttribute('channel', htmlspecialchars($mappedChannel, ENT_XML1, 'UTF-8'));
                $xmlWriter->writeAttribute('start', formatTime($program['date'], $item['start']));
                $xmlWriter->writeAttribute('stop', formatTime($program['date'], $item['end']));
                $xmlWriter->startElement('title');
                $xmlWriter->writeAttribute('lang', 'zh');
                $xmlWriter->text(htmlspecialchars($item['title'], ENT_XML1, 'UTF-8'));
                $xmlWriter->endElement(); // title
                if (!empty($item['desc'])) {
                    $xmlWriter->startElement('desc');
                    $xmlWriter->writeAttribute('lang', 'zh');
                    $xmlWriter->text(htmlspecialchars($item['desc'], ENT_XML1, 'UTF-8'));
                    $xmlWriter->endElement(); // desc
                }
                $xmlWriter->endElement(); // programme
            }
        }
    }

    // 结束 XML 文档
    $xmlWriter->endElement(); // tv
    $xmlWriter->endDocument();
    $xmlWriter->flush();

    // 所有频道数据写入完成后，生成 t.xml.gz 文件
    compressXmlFile('t.xml');
}

// 生成 t.xml.gz 压缩文件
function compressXmlFile($filePath) {
    $gzFilePath = $filePath . '.gz';

    // 打开原文件和压缩文件
    $file = fopen($filePath, 'rb');
    $gzFile = gzopen($gzFilePath, 'wb9'); // 最高压缩等级

    // 将文件内容写入到压缩文件中
    while (!feof($file)) {
        gzwrite($gzFile, fread($file, 1024 * 512));
    }

    // 关闭文件
    fclose($file);
    gzclose($gzFile);
}

// 辅助函数：将日期和时间格式化为 XMLTV 格式
function formatTime($date, $time) {
    return date('YmdHis O', strtotime("$date $time"));
}

// 处理 XML 数据并逐步存入数据库
function processXmlData($xml_data, $date, $db, $gen_list) {
    $reader = new XMLReader();
    if (!$reader->XML($xml_data)) {
        throw new Exception("无法解析 XML 数据");
    }

    $cleanChannelNames = [];

    // 读取频道数据
    while ($reader->read() && $reader->name !== 'channel');
    while ($reader->name === 'channel') {
        $channel = new SimpleXMLElement($reader->readOuterXML());
        $channelId = (string)$channel['id'];
        $cleanChannelNames[$channelId] = cleanChannelName((string)$channel->{'display-name'});
        $reader->next('channel');
    }

    // 繁简转换和频道筛选
    $simplifiedChannelNames = explode("\n", t2s(implode("\n", $cleanChannelNames)));
    $channelNamesMap = [];
    foreach ($cleanChannelNames as $channelId => $channelName) {
        $channelNameSimplified = array_shift($simplifiedChannelNames);
        // 当 gen_list 为空时，插入所有数据
        if (empty($gen_list)) {
            $channelNamesMap[$channelId] = $channelNameSimplified;
            continue;
        }
        $matchFound = false;
        foreach ($gen_list as $item) {
            if (stripos($channelNameSimplified, $item) !== false || stripos($item, $channelNameSimplified) !== false) {
                $matchFound = true;
                break;
            }
        }
        if ($matchFound) {
            $channelNamesMap[$channelId] = $channelNameSimplified;
        }
    }

    $reader->close();
    $reader->XML($xml_data); // 重置 XMLReader
    while ($reader->read() && $reader->name !== 'programme');

    $currentChannelProgrammes = [];
    $crossDayProgrammes = []; // 保存跨天的节目数据
    
    while ($reader->name === 'programme') {
        $programme = new SimpleXMLElement($reader->readOuterXML());
        $start = getFormatTime((string)$programme['start']);
        $end = getFormatTime((string)$programme['stop']);
        $channelId = (string)$programme['channel'];
    
        // 优先处理跨天数据
        if (isset($crossDayProgrammes[$channelId][$start['date']])) {
            $currentChannelProgrammes[$channelId]['diyp_data'][$start['date']] = array_merge(
                $currentChannelProgrammes[$channelId]['diyp_data'][$start['date']] ?? [],
                $crossDayProgrammes[$channelId][$start['date']]
            );
            unset($crossDayProgrammes[$channelId][$start['date']]);
        }
    
        if (isset($channelNamesMap[$channelId])) {
            $programmeData = [
                'title' => (string)$programme->title,
                'start' => $start['time'],
                'end' => $start['date'] === $end['date'] ? $end['time'] : '23:59',
                'desc' => isset($programme->desc) && (string)$programme->desc !== (string)$programme->title ? (string)$programme->desc : ''
            ];
    
            $currentChannelProgrammes[$channelId]['diyp_data'][$start['date']][] = $programmeData;
    
            // 保存跨天的节目数据
            if ($start['date'] !== $end['date'] && $end['time'] !== '00:00') {
                $crossDayProgrammes[$channelId][$end['date']][] = [
                    'title' => $programmeData['title'],
                    'start' => '00:00',
                    'end' => $end['time'],
                    'desc' => $programmeData['desc']
                ];
            }
    
            $currentChannelProgrammes[$channelId]['channel_name'] = $channelNamesMap[$channelId];
    
            // 每次达到 50 时，插入数据并保留最后一条
            if (count($currentChannelProgrammes) >= 50) {
                $lastProgramme = array_pop($currentChannelProgrammes); // 取出最后一条
                insertDataToDatabase($currentChannelProgrammes, $db); // 插入前 49 条
                $currentChannelProgrammes = [$channelId => $lastProgramme]; // 清空并重新赋值最后一条
            }
        }
    
        $reader->next('programme');
    }
    
    // 插入剩余的数据
    if ($currentChannelProgrammes) {
        insertDataToDatabase($currentChannelProgrammes, $db);
    }
    
    $reader->close();
}

// 插入数据到数据库
function insertDataToDatabase($channelsData, $db) {
    foreach ($channelsData as $channelId => $channelData) {
        $channelName = $channelData['channel_name'];
        foreach ($channelData['diyp_data'] as $date => $diypProgrammes) {
            // 生成 epg_diyp 数据内容
            $diypContent = json_encode([
                'channel_name' => $channelName,
                'date' => $date,
                'url' => 'https://github.com/TakcC/PHP-EPG-Docker-Server',
                'epg_data' => $diypProgrammes
            ], JSON_UNESCAPED_UNICODE);

            // 当天及未来数据覆盖，其他日期数据忽略
            $action = $date >= date('Y-m-d') ? 'REPLACE' : 'IGNORE';
            $stmt = $db->prepare("INSERT OR $action INTO epg_data (date, channel, epg_diyp)
                                VALUES (:date, :channel, :epg_diyp)");
            $stmt->bindValue(':date', $date, PDO::PARAM_STR);
            $stmt->bindValue(':channel', $channelName, PDO::PARAM_STR);
            $stmt->bindValue(':epg_diyp', $diypContent, PDO::PARAM_STR);
            $stmt->execute();
        }
    }
}

// 记录开始时间
$startTime = microtime(true);

// 统计更新前数据条数
$initialCount = $db->query("SELECT COUNT(*) FROM epg_data")->fetchColumn();

// 删除过期数据
deleteOldData($db, $Config['days_to_keep'], $log_messages);

// 获取限定频道列表及映射关系
$gen_res = getGenList($db);
$gen_list = $gen_res['gen_list'];
$gen_list_mapping = $gen_res['gen_list_mapping'];

// 更新数据
foreach ($Config['xml_urls'] as $xml_url) {
    // 去掉空白字符，忽略空行和以 # 开头的 URL
    $xml_url = trim($xml_url);
    if (empty($xml_url) || strpos($xml_url, '#') === 0) {
        continue;
    }
    // 去除 URL 后的注释部分
    $url_parts = explode('#', $xml_url);
    $cleaned_url = trim($url_parts[0]);

    logMessage($log_messages, "【更新地址】 $cleaned_url");
    processData($cleaned_url, $db, $log_messages, $gen_list);
}

// 判断是否生成 xmltv 文件
if ($Config['gen_xml']) {
    generateXmlFromEpgData($db, $Config['include_future_only'], $gen_list_mapping);        
    logMessage($log_messages, "【xmltv文件】 已生成 t.xml、t.xml.gz");
}

// 统计更新后数据条数
$finalCount = $db->query("SELECT COUNT(*) FROM epg_data")->fetchColumn();
$dif = $finalCount - $initialCount;
$msg = $dif > 0 ? " 增加 {$dif} 条。" : ($dif < 0 ? " 减少 " . abs($dif) . " 条。" : "");
// 记录结束时间
$endTime = microtime(true);
// 计算运行时间（以秒为单位）
$executionTime = round($endTime - $startTime, 1);
logMessage($log_messages, "【更新完成】 {$executionTime} 秒。 更新前：{$initialCount} 条，更新后：{$finalCount} 条。" . $msg);

// 将日志信息写入数据库
$log_message_str = implode("<br>", $log_messages);
$timestamp = date('Y-m-d H:i:s'); // 使用设定的时区时间
$stmt = $db->prepare('INSERT INTO update_log (timestamp, log_message) VALUES (:timestamp, :log_message)');
$stmt->bindValue(':timestamp', $timestamp, PDO::PARAM_STR);
$stmt->bindValue(':log_message', $log_message_str, PDO::PARAM_STR);
$stmt->execute();

echo implode("<br>", $log_messages);

?>
