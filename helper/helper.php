<?php
if(IS_CLI){
    \think\Console::addDefaultCommands([
        "\\zhangpeng\\gd\\MakeDemo"
    ]);
}

$fontFileName = 'fzhtjt.ttf';
$demoDir = ROOT_PATH . "gd_demo";
$fontFile = $demoDir . '/' . $fontFileName;
$sourceFontFile = __DIR__ . '/' . $fontFileName;

$extendFiles = [
    'bg.png',
]; //当前目录下使用的文件

if (!file_exists($fontFile)) {
    if (file_exists($sourceFontFile)) {
        if (!is_dir($demoDir)) {
            mkdir($demoDir, 0777, true);
        }
        copy($sourceFontFile, $fontFile);
        foreach ($extendFiles as $item) {
            copy(__DIR__ . '/' . $item, $demoDir . '/' . $item);
        }
    } else {
        echo "源字体文件" . $sourceFontFile . "不存在";
    }
}
