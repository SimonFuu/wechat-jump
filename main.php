<?php
/**
 * Created by PhpStorm.
 * User: simon
 * Date: 01/01/2018
 * Time: 2:14 PM
 * https://www.fushupeng.com
 * contact@fushupeng.com
 */

define('DEBUG', true);
define('TRAINING', false);

function similar($standard, $target, $tolerance)
{
    if (is_array($standard)) {
        $sr = $standard['r'];
        $sg = $standard['g'];
        $sb = $standard['b'];
    } else {
        $sr = ($standard >> 16) & 0xFF;
        $sg = ($standard >> 8) & 0xFF;
        $sb = $standard & 0xFF;
    }

    $tr = ($target >> 16) & 0xFF;
    $tg = ($target >> 8) & 0xFF;
    $tb = $target & 0xFF;
    return
        abs($sr - $tr) +
        abs($sg - $tg) +
        abs($sb - $tb) <= $tolerance;
}

function screenCapturing($path)
{
    print_r("Screen capturing.....\r\n");
    ob_start();
    system('adb shell screencap -p /sdcard/screen.png');
    system(sprintf('adb pull /sdcard/screen.png %s', $path));
    ob_end_clean();
}

function getStartAndEnd($image, $width, $height, $conf)
{
    $border = getScanBorder($image, $width, $height, $conf);
    try {
        $startPoint = getStartPoint($image, $width, $border, $conf);
        $endPoint = getEndPoint($image, $width, $startPoint, $conf, $border);
        return ['start' => $startPoint, 'end' => $endPoint];
    } catch (Exception $e) {
        echo $e -> getMessage() . "\r\n";
        die;
    }
}

function getScanBorder($image, $width, $height, $conf)
{
    $heightStartBorder = $height / 3;
    $heightEndBorder = 2 * $height / 3;
    $checkWidth = $width - $conf['character']['width'];
    for ($j = $heightStartBorder; $j < $heightEndBorder; $j++) {
        $firstColorIndex = imagecolorat($image, 0, $j);
        for ($i = 0; $i < $checkWidth; $i = $i + $conf['character']['bottomWidth']) {
            $colorIndex = imagecolorat($image, $i, $j);
            if (!similar($firstColorIndex, $colorIndex, $conf['colorTolerance'])) {
                return ['start' => $j, 'end' => $heightEndBorder];
            }
        }
    }
    return ['start' => $heightStartBorder, 'end' => $heightEndBorder];
}

function getStartPoint($image, $width, $border, $conf)
{
    $horizontalStart = $width / 8;
    $horizontalEnd = 7 * $width / 8;
    $startPositionXSum = 0;
    $startPositionXCount = 0;
    $startPositionY = 0;
    $points = [];
    for ($j = $border['start']; $j < $border['end']; $j++) {
        for ($i = $horizontalStart; $i < $horizontalEnd; $i += $conf['character']['bottomWidth']) {
            $colorIndex = imagecolorat($image, $i, $j);
            if (similar($conf['character']['colorIndex'], $colorIndex, $conf['colorTolerance'])) {
                $startPositionXSum += $i;
                $startPositionXCount += 1;
                $startPositionY = max($startPositionY, $j);
                $points[] = ['x' => $i, 'y' => $j];
            }
        }
    }
    if ($startPositionXCount == 0) {
        throw new Exception('Error in finding the character!');
    }
    return [
        'x' => $startPositionXSum / $startPositionXCount,
        'y' => $startPositionY - $conf['character']['offset'],
        'matchPoints' => $points
    ];
}

function getEndPoint($image, $width, $startPoint, $conf, $border)
{
    $points = [];
    $endPositionXSum = 0;
    $endPositionXCount = 0;
    if ($startPoint['x'] > $width / 2) {
        // 起始点在中轴的右侧，终点则在中轴左侧，水平方向检查的距离为 0 —— 中轴
        $horizontalStart = 1;    // 防止边缘溢出
        $horizontalEnd = $width / 2 - $conf['character']['width'] / 2;
    } else {
        // 起始点在中轴的左侧，终点则在中轴右侧，水平方向检查的距离为 中轴 —— $width
        $horizontalStart = $width / 2 + $conf['character']['width'] / 2;
        $horizontalEnd = $width-1;    // 防止边缘溢出
    }

    $line = 0;
    for ($j = $border['start']; $j < $startPoint['y']; $j++) {
        $lineStartColorIndex = imagecolorat($image, 0, $j);
        $matchMark = false;
        for ($i = $horizontalEnd; $i > $horizontalStart; $i--) {
            $colorIndex = imagecolorat($image, $i, $j);
            if (!similar($lineStartColorIndex, $colorIndex, $conf['colorTolerance'])) {
                $endPositionXSum += $i;
                $endPositionXCount += 1;
                $points[] = ['x' => $i, 'y' => $j];
                $matchMark = true;
            }

        }
        // 取目标区域的前十行像素进行判断，以排除阴影区域造成的干扰
        if ($matchMark) {
            $line++;
            if ($line > 10) {
                break;
            }
        }
    }
    if ($endPositionXCount == 0) {
        throw new Exception('Error in finding the end point!');
    }
    // 计算 Y 值：
    // 根据 https://github.com/wangshub/wechat_jump_game 提示，
    // 当前使用的方法为 起止点连线与水平方向呈大约30度夹角
    // startPoint[Y] - tan 30度 * |endPoint[x] - startPoint[x]|
    $endPositionX = $endPositionXSum / $endPositionXCount;
    return [
        'x' => $endPositionX,
        'y' => $startPoint['y'] - abs($endPositionX - $startPoint['x']) * sqrt(3) / 3,
        'matchPoints' => $points
    ];
}

function getPressTime($startPoint, $endPoint, $conf)
{
    return (int) (
        sqrt(
        pow($startPoint['x'] - $endPoint['x'], 2) + pow($startPoint['y'] - $endPoint['y'], 2)
        ) * $conf['pressTimeRatio']
    );
}

function doJump($pressTime)
{
    echo "Jumping......\r\n";
    $cmd = sprintf('adb shell input swipe 320 410 320 410 %s', $pressTime);
    echo sprintf("Sending jump command %s \r\n", $cmd);
    system($cmd);
}

function debug($image, $width, $height, $startPoint, $endPoint, $path, $matchPoints = null)
{
    $target = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate ( $target, 255, 255, 255 );
    imagefill($target, 0, 0, $white);
    imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, $width, $height);
    $startPointColor = imagecolorallocate($target, 255, 0, 0);
    $endPointColor = imagecolorallocate($target, 0, 0, 255);
    $startToEndColor = imagecolorallocate($target, 0, 0, 0);
    $midLineColor = imagecolorallocate($target, 0, 255, 0);
    // 中线
    imageline($target, 540, 0, 540, $height, $midLineColor);
    // 起始点标记
    imageellipse($target, $startPoint['x'], $startPoint['y'], 10, 10, $startPointColor);
    imageline($target, 0, $startPoint['y'], $width, $startPoint['y'], $startPointColor);
    imageline($target, $startPoint['x'], 0, $startPoint['x'], $height, $startPointColor);
    // 终点标记
    imageellipse($target, $endPoint['x'], $endPoint['y'], 10, 10, $endPointColor);
    imageline($target, 0, $endPoint['y'], $width, $endPoint['y'], $endPointColor);
    imageline($target, $endPoint['x'], 0, $endPoint['x'], $height, $endPointColor);
    // 起止点连线
    imageline($target, $startPoint['x'], $startPoint['y'], $endPoint['x'], $endPoint['y'], $startToEndColor);

    if (!is_null($matchPoints)) {
        $color = imagecolorallocate($target, 224, 0, 0);
        foreach ($matchPoints as $point) {
            imageline($target, $point['x'], $point['y'], $point['x'], $point['y'], $color);
        }
    }
    imagepng($target, $path . '/' . time() . '.png');
    imagedestroy($target);
}

function init()
{
    $path = '';
    // 创建保存Debug文件目录
    if (TRAINING || DEBUG) {
        echo "创建存储debug文件目录...\r\n";
        $storePath = $path = './debug/' . date('Ymd');
        for ($i = 1;; $i ++) {
            if (is_dir($path)) {
                $path = $storePath . '_' . $i;
            } else {
                @mkdir($path);
                break;
            }
        }
    }
    return $path;
}

function training($max, $conf, $path)
{
    $max = $max >= 107 ? 107 : $max;
    for ($i = 35; $i < $max; $i++) {
        $image = imagecreatefrompng('./training-data/' . $i . '.png');
        $width = imagesx($image);
        $height = imagesy($image);
        $points = getStartAndEnd($image, $width, $height, $conf);
        $matchPoints = array_merge($points['start']['matchPoints'], $points['end']['matchPoints']);
        echo "debugging....\r\n";
        debug($image, $width, $height, $points['start'], $points['end'], $path, $matchPoints);
        sleep(1);
    }
}

function run($conf, $path)
{
    for ($i = 0; ; $i++) {
        screenCapturing($conf['captureFile']);
        $image = imagecreatefrompng($conf['captureFile']);
        $width = imagesx($image);
        $height = imagesy($image);
        $points = getStartAndEnd($image, $width, $height, $conf);
        $pressTime = getPressTime($points['start'], $points['end'], $conf);
        echo sprintf(
            "【%s】 From [X: %s, Y: %s] -> [X: %s, Y: %s], press time is %s. \r\n",
            $i, $points['start']['x'], $points['start']['y'], $points['end']['x'], $points['end']['y'], $pressTime
        );

        doJump($pressTime);
        if (DEBUG) {
            debug($image, $width, $height, $points['start'], $points['end'], $path);
        }
        sleep($conf['sleep']);
    }
}

function main($conf = array())
{
    echo "Thread starting..... \r\n";
    $path = init();
    if (TRAINING) {
        training(100, $conf, $path);
    } else {
        run($conf, $path);
    }
}

main(require_once 'conf.php');
