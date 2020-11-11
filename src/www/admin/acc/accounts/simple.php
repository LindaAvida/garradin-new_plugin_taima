<?php
namespace Garradin;

use Garradin\Accounting\Accounts;
use Garradin\Entities\Accounting\Account;

require_once __DIR__ . '/../_inc.php';

if (!CURRENT_YEAR_ID) {
	Utils::redirect(ADMIN_URL . 'acc/years/?msg=OPEN');
}

$types = [
	Account::TYPE_REVENUE => 'Recettes',
	Account::TYPE_EXPENSE => 'Dépenses',
	Account::TYPE_BANK => 'Banques',
	Account::TYPE_CASH => 'Caisses',
	Account::TYPE_OUTSTANDING => 'En attente',
	Account::TYPE_THIRD_PARTY => 'Dettes et créances',
	0 => 'Autres',
];

$type = qg('type');

if (null == $type || !array_key_exists($type, $types)) {
	$type = key($types);
}

$list = Accounts::listByType(CURRENT_YEAR_ID, $type ?: null);
$list->setTitle(sprintf('Suivi - %s', $types[$type]));
$list->loadFromQueryString();

$tpl->assign(compact('type', 'list', 'types'));

$tpl->display('acc/accounts/simple.tpl');