<?php
require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Db\BaseRecordHandler;

$pg = new PgConnection('labelcenter');

$mapping = [
    'id'          => 'id',
    'name'        => 'name',
    'template_id' => 'tempid',
    'storecard_id'=> 'scid',
    'notice'      => 'notice',
    'qpp'         => 'qpp'
];

$handler = new BaseRecordHandler($pg, 'labels', 'public.spelman_lc_labels', $mapping);
$handler->handleRequest();
