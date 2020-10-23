<?php
namespace Garradin;

use Garradin\Accounting\Years;
use Garradin\Accounting\Graph;

require_once __DIR__ . '/../_inc.php';

$session->requireAccess('compta', Membres::DROIT_ACCES);

$years = new Years;

$tpl->assign('graphs', Graph::URL_LIST);

$tpl->assign('years', $years->listOpen());

$tpl->display('acc/index.tpl');