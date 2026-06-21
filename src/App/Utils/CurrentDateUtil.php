<?php

namespace App\Utils;

class CurrentDateUtil
{
    public static function getCurrentDate(): string
    {
        return date('l, F j, Y');
    }

    public static function getCurrentTime(): string
    {
        $hour = (int)date('G');
        $minute = (int)date('i');
        $rounded = $minute >= 30 ? 30 : 0;
        return sprintf('%02d:%02d', $hour, $rounded);
    }

    public static function getTomorrowDate(): string
    {
        return date('l, F j, Y', strtotime('+1 day'));
    }
}
