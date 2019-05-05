<?php
if(IS_CLI){
    \think\Console::addDefaultCommands([
        "\\zhangpeng\\gd\\MakeDemo"
    ]);
}

$demoDir = ROOT_PATH . "gd_demo";

$extendFiles = [
    'ziti.ttf',
    'bg.png',
]; //当前目录下使用的文件

if (!file_exists($demoDir . '/' . $extendFiles[0])) {
    if (file_exists(__DIR__ . '/' . $extendFiles[0])) {
        if (!is_dir($demoDir)) {
            mkdir($demoDir, 0777, true);
        }
        foreach ($extendFiles as $item) {
            copy(__DIR__ . '/' . $item, $demoDir . '/' . $item);
        }
    } else {
        echo "源字体文件" . $demoDir . '/' . $extendFiles[0] . "不存在";
    }
}

