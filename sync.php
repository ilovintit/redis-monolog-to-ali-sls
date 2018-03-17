<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/aliyun-log-php-sdk/Log_Autoload.php';

class sync
{


    public function run()
    {
        var_dump(new Aliyun_Log_Client('', '', ''));
    }
}

(new sync())->run();