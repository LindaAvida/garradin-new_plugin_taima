{include file="admin/_head.tpl" title="Écriture n°%d"|args:$transaction.id current="acc"}

{if $session->canAccess('compta', Membres::DROIT_ADMIN) && !$transaction->validated && !$tr_year->closed}
<nav class="tabs">
	<ul>
		<li><a href="edit.php?id={$transaction.id}">Modifier cette écriture</a></li>
		<li><a href="delete.php?id={$transaction.id}">Supprimer cette écriture</a></li>
	</ul>
</nav>
{/if}

<dl class="describe">
	<dt>Libellé</dt>
	<dd><h2>{$transaction.label}</h2></dd>
	<dt>Date</dt>
	<dd>{$transaction.date|date_fr:'l j F Y (d/m/Y)'}</dd>
	<dt>Numéro pièce comptable</dt>
	<dd>{if trim($transaction.numero_piece)}{$transaction.numero_piece}{else}<em>Non renseigné</em>{/if}</dd>

	<dt>Exercice</dt>
	<dd>
		<a href="{$admin_url}acc/years/year.php?id={$transaction.id_year}">{$tr_year.label}</a>
		| Du {$tr_year.start_date|date_fr:'d/m/Y'} au {$tr_year.end_date|date_fr:'d/m/Y'}
		| <strong>{if $tr_year.closed}Clôturé{else}En cours{/if}</strong>
	</dd>

	{if $transaction.id_projet}
		<dt>Projet</dt>
		<dd>
			<a href="{$admin_url}compta/projets/">{$projet.libelle}</a>
		</dd>
	{/if}

	<dt>Opération créée par</dt>
	<dd>
		{if $transaction.id_auteur}
			{if $session->canAccess('compta', Membres::DROIT_ACCES)}
				<a href="{$admin_url}membres/fiche.php?id={$transaction.id_auteur}">{$nom_auteur}</a>
			{else}
				{$nom_auteur}
			{/if}
		{else}
			<em>membre supprimé</em>
		{/if}
	</dd>

	<dt>Opération liée à</dt>
	<dd>
		{if empty($related_members)}
			Aucun membre n'est lié à cette opération.
		{else}
			{foreach from=$related_members item="membre"}
				<a href="{$admin_url}membres/{if $membre.id_cotisation}cotisations{else}fiche{/if}.php?id={$membre.id_membre}">{if $membre.id_cotisation}Cotisation pour {/if}{$membre.identite}</a>
			{/foreach}
		{/if}
	</dd>

	<dt>Remarques</dt>
	<dd>{if trim($transaction.notes)}{$transaction.notes}{else}<em>Non renseigné</em>{/if}</dd>

	<dt>Fichiers joints</dt>
	{foreach from=$files item="file"}
	<dd>
		<aside class="file">
			<a href="{$file.url}">{$file.nom}</a>
			<small>({$file.type}, {$file.taille|format_bytes})</small>
		</aside>
	</dd>
	{/foreach}
</dl>

<table class="list multi">
	<thead>
		<tr>
			<th colspan="2">Comptes</th>
			<td>Débit</td>
			<td>Crédit</td>
			<td>Libellé</td>
			<td>Référence</td>
		</tr>
	</thead>
	<tbody>
		{foreach from=$transaction->getLinesWithAccounts() item="line"}
		<tr>
			<td><a href="{$admin_url}acc/accounts/journal.php?id={$line.id_account}">{$line.account_code}</a></td>
			<td>{$line.account_name}</td>
			<td>{$line.debit|escape|html_money}&nbsp;{$config.monnaie}</td>
			<td>{$line.credit|escape|html_money}&nbsp;{$config.monnaie}</td>
			<td>{$line.label}</td>
			<td>{$line.reference}</td>
		</tr>
		{/foreach}
	</tbody>
</table>

{include file="admin/_foot.tpl"}