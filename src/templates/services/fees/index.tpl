{include file="admin/_head.tpl" title="%s — Tarifs"|args:$service.label current="membres/services" js=1}

{include file="services/_nav.tpl" current="index"}

{if count($list)}
	<table class="list">
		<thead>
			<th>Tarif</th>
			<td>Montant</td>
			<td>Membres à jour et ayant payé</td>
			<td>Membres en attente de règlement</td>
			<td></td>
		</thead>
		<tbody>
			{foreach from=$list item="row"}
				<tr>
					<th><a href="details.php?id={$row.id}">{$row.label}</a></th>
					<td>
						{if $row.formula}
							Formule
						{else}
							{$row.amount|html_money|raw}
						{/if}
					</td>
					<td class="num">{$row.nb_users_ok}</td>
					<td class="num">{$row.nb_users_unpaid}</td>
					<td class="actions">
						{linkbutton shape="users" label="Liste des inscrits" href="services/fees/details.php?id=%d"|args:$row.id}
						{if $session->canAccess('membres', Membres::DROIT_ADMIN)}
							{linkbutton shape="edit" label="Modifier" href="services/fees/edit.php?id=%d"|args:$row.id}
							{linkbutton shape="delete" label="Supprimer" href="services/fees/delete.php?id=%d"|args:$row.id}
						{/if}
					</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
{else}
	<p class="alert">Il n'y a aucun tarif enregistré.</p>
{/if}

{if $session->canAccess('membres', Membres::DROIT_ADMIN)}

{form_errors}

<form method="post" action="{$self_url}">

	<fieldset>
		<legend>Ajouter un tarif</legend>
		<dl>
			{input name="label" type="text" required=1 label="Libellé"}
			{input name="description" type="textarea" label="Description"}

			{input name="amount" type="money" label="Montant fixe (en %s)"|args:$config.monnaie help="Pour pratiquer le prix libre il suffira de marquer comme payé les membres, sans considérer le montant."}
			{input name="formula" type="textarea" label="Alternativement : formule de calcul"}
			<dd class="help">
				<a href="https://fossil.kd2.org/garradin/wiki?name=Formule_calcul_activit%C3%A9">Aide sur les formules de calcul</a>
			</dd>
		</dl>
	</fieldset>

	<p class="submit">
		{csrf_field key="fee_new"}
		<input type="submit" name="add" value="Ajouter &rarr;" />
	</p>

</form>

<script type="text/javascript">
{literal}
(function () {
	var hide = [];
	if (!$('#f_period_1').checked)
		hide.push('.period_1');

	if (!$('#f_period_2').checked)
		hide.push('.period_2');

	g.toggle(hide, false);

	function togglePeriod()
	{
		g.toggle(['.period_1', '.period_2'], false);

		if (this.checked && this.value == 1)
			g.toggle('.period_1', true);
		else if (this.checked && this.value == 2)
			g.toggle('.period_2', true);
	}

	$('#f_period_0').onchange = togglePeriod;
	$('#f_period_1').onchange = togglePeriod;
	$('#f_period_2').onchange = togglePeriod;
})();
{/literal}
</script>

{/if}

{include file="admin/_foot.tpl"}