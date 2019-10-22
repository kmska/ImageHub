<?php

namespace App\Utils;


class StringUtil
{
    public static function stripExtension($filename)
    {
        $dotIndex = strrpos($filename, '.');
        if($dotIndex > 0) {
            $filename = substr($filename, 0, $dotIndex);
        }
        return $filename;
    }
}
