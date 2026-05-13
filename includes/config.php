<?php
declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'portale_parrucchieri';
const DB_USER = 'lu3g_usr';
const DB_PASS = 'k8E7_li49';
const APP_NAME = 'Portale Parrucchieri';
const APP_URL = '';
const DEFAULT_SLOT_MINUTES = 30;
const VAPID_PUBLIC_KEY = 'BBPwpGEjDgUZ1vjn3u_2BfvREwfbhSH39LDP6wuC_46xzERsI06tTJ5GxArN-MfX13TKNAEyK_Q3w9djiF6GEes';
const VAPID_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgVPkSWhXSkrdsUBNp
7i7Muv3lNMfqclz17ezUgsHGlm+hRANCAAQT8KRhIw4FGdb4597v9gX70RMH24Uh
9/Swz+sLgv+OscxEbCNOrUyeRsQKzfjH19d0yjQBMiv0N8PXY4hehhHr
-----END PRIVATE KEY-----
PEM;
const VAPID_SUBJECT = 'mailto:admin@salone.local';
const OPENING_HOURS = [
    1 => [['09:00', '13:00'], ['15:00', '19:30']],
    2 => [['09:00', '13:00'], ['15:00', '19:30']],
    3 => [['09:00', '13:00'], ['15:00', '19:30']],
    4 => [['09:00', '13:00'], ['15:00', '19:30']],
    5 => [['09:00', '13:00'], ['15:00', '19:30']],
    6 => [['09:00', '13:00']],
    7 => [],
];
