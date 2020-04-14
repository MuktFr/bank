<?php
/*
 * Paiement Bancaire
 * module de paiement bancaire multi prestataires
 * stockage des transactions
 *
 * Auteurs :
 * Cedric Morin, Nursit.com
 * (c) 2012-2019 - Distribue sous licence GNU/GPL
 *
 */
if (!defined('_ECRIRE_INC_VERSION')){
	return;
}


// securite : on initialise une globale le temps de la config des prestas
if (isset($GLOBALS['meta']['bank_paiement'])
	AND $GLOBALS['config_bank_paiement'] = unserialize($GLOBALS['meta']['bank_paiement'])){

	foreach ($GLOBALS['config_bank_paiement'] as $nom => $config){
		if (strncmp($nom, "config_", 7)==0
			AND isset($config['actif'])
			AND $config['actif']
			AND isset($config['presta'])
			AND $presta = $config['presta']){
			// inclure le fichier config du presta correspondant
			include_spip("presta/$presta/config");
		}
	}

	// securite : on ne conserve pas la globale en memoire car elle contient des donnees sensibles
	unset($GLOBALS['config_bank_paiement']);
}

if (!function_exists('affiche_monnaie')){
	function affiche_monnaie($valeur, $decimales = 2, $unite = true){
		if ($unite===true){
			$unite = "&nbsp;EUR";
			if (substr(trim($valeur), -1)=="%"){
				$unite = "&nbsp;%";
			}
		}
		if (!$unite){
			$unite = "";
		}
		return sprintf("%.{$decimales}f", $valeur) . $unite;
	}
}

/**
 * Fonction appelee par la balise #PAYER_ACTE et #PAYER_ABONNEMENT
 * @param array|string $config
 *   string dans le cas "gratuit" => on va chercher la config via bank_config()
 * @param string $type
 * @param int $id_transaction
 * @param string $transaction_hash
 * @param array|string|null $options
 * @return string
 */
function bank_affiche_payer($config, $type, $id_transaction, $transaction_hash, $options = null){
	// compatibilite ancienne syntaxe, titre en 4e argument de #PAYER_XXX
	if (is_string($options)){
		$options = array(
			'title' => $options,
		);
	}
	// invalide ou null ?
	if (!is_array($options)){
		$options = array();
	}

	// $config de type string ?
	include_spip('inc/bank');
	if (is_string($config)){
		$config = bank_config($config, $type=='abo');
	}

	$quoi = ($type=='abo' ? 'abonnement' : 'acte');
	
	$devise_defaut = bank_devise_defaut();
	
	if (!bank_tester_devise_presta($config['presta'], $devise_defaut['code'])) {
		spip_log('La devise ' . $devise_defaut['code'] . 'n’est pas supportée pour presta=' . $config['presta'], 'bank' . _LOG_ERREUR);
		return '';
	}
	
	if (!$payer = charger_fonction($quoi, 'presta/' . $config['presta'] . '/payer', true)) {
		spip_log("Pas de payer/$quoi pour presta=" . $config['presta'], "bank" . _LOG_ERREUR);
		return '';
	}

	return $payer($config, $id_transaction, $transaction_hash, $options);
}

/**
 * Afficher le bouton pour gerer/interrompre un abonnement
 * @param array|string $config
 * @param string $abo_uid
 * @return array|string
 */
function bank_affiche_gerer_abonnement($config, $abo_uid){
	// $config de type string ?
	include_spip('inc/bank');
	if (is_string($config)){
		$config = bank_config($config, true);
	}

	if ($trans = sql_fetsel("*", "spip_transactions", $w = "abo_uid=" . sql_quote($abo_uid) . ' AND mode LIKE ' . sql_quote($config['presta'] . '%') . " AND " . sql_in('statut', array('ok', 'attente')), '', 'id_transaction')){
		$config = bank_config($trans['mode']);
		$fond = "modeles/gerer_abonnement";
		if (trouver_fond($f = "presta/" . $config['presta'] . "/payer/gerer_abonnement")){
			$fond = $f;
		}
		return recuperer_fond($fond, array('presta' => $config['presta'], 'id_transaction' => $trans['id_transaction'], 'abo_uid' => $abo_uid));
	}

	return "";
}


/**
 * Trouver un logo pour un presta donne
 * Historiquement les logos etaient des .gif, possiblement specifique aux prestas
 * On peut les surcharger par un .png (ou un .svg a partir de SPIP 3.2.5)
 * @param $mode
 * @param $logo
 * @return bool|string
 */
function bank_trouver_logo($mode, $logo){
	static $svg_allowed;
	if (is_null($svg_allowed)){
		$svg_allowed = false;
		// _SPIP_VERSION_ID definie en 3.3 et 3.2.5-dev
		if (defined('_SPIP_VERSION_ID') and _SPIP_VERSION_ID>=30205){
			$svg_allowed = true;
		} else {
			$branche = explode('.', $GLOBALS['spip_version_branche']);
			if ($branche[0]==3 and $branche[1]==2 and $branche[2]>=5){
				$svg_allowed = true;
			}
		}
	}

	if (substr($logo, -4)=='.gif'
		and $f = bank_trouver_logo($mode, substr(strtolower($logo), 0, -4) . ".png")){
		return $f;
	}
	if ($svg_allowed
		and substr($logo, -4)=='.png'
		and $f = bank_trouver_logo($mode, substr(strtolower($logo), 0, -4) . ".svg")){
		return $f;
	}

	// d'abord dans un dossier presta/
	if ($f = find_in_path("presta/$mode/logo/$logo")){
		return $f;
	} // sinon le dossier generique
	elseif ($f = find_in_path("bank/logo/$logo")) {
		return $f;
	}
	return "";
}

/**
 * Annoncer SPIP + plugin&version pour les logs de certains providers
 * @param string $format
 * @return string
 */
function bank_annonce_version_plugin($format = 'string'){
	$infos = array(
		'name' => 'SPIP ' . $GLOBALS['spip_version_branche'] . ' + Bank',
		'url' => 'https://github.com/nursit/bank',
		'version' => '',
	);
	include_spip('inc/filtres');
	if ($info_plugin = chercher_filtre("info_plugin")){
		$infos['version'] = 'v' . $info_plugin("bank", "version");
	}

	if ($format==='string'){
		return $infos['name'] . $infos['version'] . '(' . $infos['url'] . ')';
	}

	return $infos;
}

/**
 * Renvoie la devise par défaut utilisée par Bank, modifiable par pipeline
 * 
 * @pipeline_appel bank_devise_defaut
 * @return string
 *     Identifiant ISO 4217 alpha d'une devise
 */
function bank_devise_defaut() {
	$devise = array(
		'code' => 'EUR',
		'code_num' => 978,
		'fraction' => 2,
	);
	
	$devise = pipeline('bank_devise_defaut', $devise);
	
	return $devise;
}

/**
 * Tester si une devise est supportée par un prestataire
 * 
 * @param $devise 
 * 		Devise à tester en code ISO 4217 alphabétique
 * @param $presta
 * 		Type du prestataire à tester
 * @return bool
 * 		Renvoie true si la devise est ok, false sinon
 * 
 */
function bank_tester_devise_presta($presta, $devise = null) {
	$ok = false;
	
	// Si pas de devise, on prend celle générale par défaut
	if (!$devise) {
		$devise_defaut = bank_devise_defaut();
		$devise = $devise_defaut['code'];
	}

	// Par défaut on accepte l'euro comme avant, ce qui évite d'implémenter partout
	$devises_ok = array('EUR');

	// Si le presta a une fonction qui définit les devises supportées, on l'utilise.
	// Elle retourne soit un tableau, soit un booléen pour les accepter toutes.
	if ($lister_devises = charger_fonction('lister_devises', 'presta/' . $presta, true)) {
		$devises_ok = $lister_devises();
	}

	// Et enfin on teste
	if (is_array($devises_ok)) {
		// On normalise
		$devise = strtoupper($devise);
		$devises_ok = array_map('strtoupper', $devises_ok);
		$ok = in_array($devise, $devises_ok);
	} elseif (is_bool($devises_ok)) {
		$ok = $devises_ok;
	}

	return $ok;
}
