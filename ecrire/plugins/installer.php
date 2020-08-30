<?php

/***************************************************************************\
 *  SPIP, Système de publication pour l'internet                           *
 *                                                                         *
 *  Copyright © avec tendresse depuis 2001                                 *
 *  Arnaud Martin, Antoine Pitrou, Philippe Rivière, Emmanuel Saint-James  *
 *                                                                         *
 *  Ce programme est un logiciel libre distribué sous licence GNU/GPL.     *
 *  Pour plus de détails voir le fichier COPYING.txt ou l'aide en ligne.   *
\***************************************************************************/

/**
 * Gestion de l'installation des plugins
 *
 * @package SPIP\Core\Plugins
 **/


if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Installe ou retire un plugin
 *
 * Permet d'installer ou retirer un plugin en incluant les fichiers
 * associés et en lançant les fonctions spécifiques.
 *
 * 1. d'abord sur l'argument `test`,
 * 2. ensuite sur l'action demandée si le test repond `false`
 * 3. enfin sur l'argument `test` à nouveau.
 *
 * L'index `install_test` du tableau résultat est un tableau formé :
 *
 *  - du résultat 3
 *  - des echo de l'étape 2
 *
 * @note
 *     La fonction quitte (retourne false) si le plugin
 *     n'a pas de version d'installation définie
 *     (information `schema` dans le paquet.xml)
 *
 * @param string $plug
 *     Nom du plugin
 * @param string $action
 *     Nom de l'action (install|uninstall)
 * @param string $dir_type
 *     Répertoire du plugin
 * @return array|bool
 *     - False si le plugin n'a pas d'installation,
 *     - true si déjà installé,
 *     - le tableau de get_infos sinon
 */
function plugins_installer_dist($plug, $action, $dir_type = '_DIR_PLUGINS') {

	// Charger les informations du XML du plugin et vérification de l'existence d'une installation
	$get_infos = charger_fonction('get_infos', 'plugins');
	$infos = $get_infos($plug, false, constant($dir_type));
	if (!isset($infos['install']) or !$infos['install']) {
		return false;
	}

	// Passer en chemin absolu si possible, c'est plus efficace
	$dir = str_replace('_DIR_', '_ROOT_', $dir_type);
	if (!defined($dir)) {
		$dir = $dir_type;
	}
	$dir = constant($dir);
	foreach ($infos['install'] as $file) {
		$file = $dir . $plug . "/" . trim($file);
		if (file_exists($file)) {
			include_once($file);
		}
	}

	// Détermination de la table meta et du nom de la meta plugin
	$table = 'meta';
	if (isset($infos['meta']) and ($infos['meta'] !== 'meta')) {
		$table = $infos['meta'];
		// S'assurer que les metas de la table spécifique sont bien accessibles dans la globale
		lire_metas($table);
	}
	$nom_meta = $infos['prefix'] . '_base_version';

	// Détermination de la fonction à appeler et de ses arguments
	$f = $infos['prefix'] . "_install";
	if (!function_exists($f)) {
		$f = isset($infos['schema']) ? 'spip_plugin_install' : '';
		$arg = $infos;
		// On passe la table et la meta pour éviter de les recalculer dans la fonction appelée
		$arg['meta'] = $table;
		$arg['nom_meta'] = $nom_meta;
	} else {
		// Ancienne méthode d'installation - TODO à supprimer à terme
		// stupide: info deja dans le nom
		$arg = $infos['prefix'];
	}
	$version = isset($infos['schema']) ? $infos['schema'] : '';

	if (!$f) {
		// installation sans operation particuliere
		$infos['install_test'] = array(true, '');
		return $infos;
	}

	// Tester si l'action demandée est nécessaire ou pas.
	$test = $f('test', $arg, $version);
	if ($action == 'uninstall') {
		$test = !$test;
	}
	// Si deja fait, on ne fait rien et on ne dit rien
	if ($test) {
		return true;
	}

	// Si install et que l'on a la meta d'installation, c'est un upgrade. On le consigne dans $infos
	// pour renvoyer le bon message en retour de la fonction.
	if ($action == 'install' && !empty($GLOBALS[$table][$nom_meta])) {
		$infos['upgrade'] = true;
	}

	// executer l'installation ou l'inverse
	// et renvoyer la trace (mais il faudrait passer en AJAX plutot)
	ob_start();
	$f($action, $arg, $version);
	$aff = ob_get_contents();
	ob_end_clean();

	// vider le cache des descriptions de tables a chaque (de)installation
	$trouver_table = charger_fonction('trouver_table', 'base');
	$trouver_table('');
	$infos['install_test'] = array($f('test', $arg, $version), $aff);

	// Si la table meta n'est pas spip_meta et qu'on est dans la première installation du plugin
	// on force la création du fichier cache à la date du moment.
	// On relit les metas de la table pour être sur que la globale soit à jour pour touch_meta.
	if (
		($table !== 'meta')
		and ($action == 'install')
		and empty($infos['upgrade'])
	) {
		touch_meta(false, $table);
	}

	return $infos;
}

/**
 * Fonction standard utilisée par defaut pour install/desinstall
 *
 * @param string $action
 *     Nom de l'action (install|uninstall)
 * @param array  $infos
 *     Tableau des informations du XML du plugin complété par le nom et la table meta
 * @param string $version_cible
 *     Référence de la version du schéma de données cible
 *
 * @return bool|void
 */
function spip_plugin_install($action, $infos, $version_cible) {
	$nom_meta = $infos['nom_meta'];
	$table = $infos['meta'];
	switch ($action) {
		case 'test':
			return (isset($GLOBALS[$table])
				and isset($GLOBALS[$table][$nom_meta])
				and spip_version_compare($GLOBALS[$table][$nom_meta], $version_cible, '>='));
			break;
		case 'install':
			if (function_exists($upgrade = $infos['prefix'] . '_upgrade')) {
				$upgrade($nom_meta, $version_cible, $table);
			}
			break;
		case 'uninstall':
			if (function_exists($vider_tables = $infos['prefix'] . '_vider_tables')) {
				$vider_tables($nom_meta, $table);
			}
			break;
	}
}


/**
 * Compare 2 numéros de version entre elles.
 *
 * Cette fonction est identique (arguments et retours) a la fonction PHP
 * version_compare() qu'elle appelle. Cependant, cette fonction reformate
 * les numeros de versions pour ameliorer certains usages dans SPIP ou bugs
 * dans PHP. On permet ainsi de comparer 3.0.4 à 3.0.* par exemple.
 *
 * @param string $v1
 *    Numero de version servant de base a la comparaison.
 *    Ce numero ne peut pas comporter d'etoile.
 * @param string $v2
 *    Numero de version a comparer.
 *    Il peut posseder des etoiles tel que 3.0.*
 * @param string $op
 *    Un operateur eventuel (<, >, <=, >=, =, == ...)
 * @return int|bool
 *    Sans operateur : int. -1 pour inferieur, 0 pour egal, 1 pour superieur
 *    Avec operateur : bool.
 **/
function spip_version_compare($v1, $v2, $op = null) {
	$v1 = strtolower(preg_replace(',([0-9])[\s.-]?(dev|alpha|a|beta|b|rc|pl|p),i', '\\1.\\2', $v1));
	$v2 = strtolower(preg_replace(',([0-9])[\s.-]?(dev|alpha|a|beta|b|rc|pl|p),i', '\\1.\\2', $v2));
	$v1 = str_replace('rc', 'RC', $v1); // certaines versions de PHP ne comprennent RC qu'en majuscule
	$v2 = str_replace('rc', 'RC', $v2); // certaines versions de PHP ne comprennent RC qu'en majuscule

	$v1 = explode('.', $v1);
	$v2 = explode('.', $v2);
	// $v1 est toujours une version, donc sans etoile
	while (count($v1) < count($v2)) {
		$v1[] = '0';
	}

	// $v2 peut etre une borne, donc accepte l'etoile
	$etoile = false;
	foreach ($v1 as $k => $v) {
		if (!isset($v2[$k])) {
			$v2[] = ($etoile and (is_numeric($v) or $v == 'pl' or $v == 'p')) ? $v : '0';
		} else {
			if ($v2[$k] == '*') {
				$etoile = true;
				$v2[$k] = $v;
			}
		}
	}
	$v1 = implode('.', $v1);
	$v2 = implode('.', $v2);

	return $op ? version_compare($v1, $v2, $op) : version_compare($v1, $v2);
}


/**
 * Retourne un tableau des plugins activés sur le site
 *
 * Retourne la meta `plugin` désérialisée.
 * Chaque élément du tableau est lui-même un tableau contenant
 * les détails du plugin en question : répertoire et version.
 *
 * @note
 *   Si le contenu de la meta n’est pas un tableau, cette fonction transforme
 *   l’ancien format en tableau sérialisé pour être conforme au nouveau fonctionnement (SPIP >= 1.9.2)
 *
 * @return array Tableau des plugins actifs
 **/
function liste_plugin_actifs() {
	$liste = isset($GLOBALS['meta']['plugin']) ? $GLOBALS['meta']['plugin'] : '';
	if (!$liste) {
		return array();
	}
	if (!is_array($liste = unserialize($liste))) {
		// compatibilite pre 1.9.2, mettre a jour la meta
		spip_log("MAJ meta plugin vieille version : $liste", "plugin");
		$new = true;
		list(, $liste) = liste_plugin_valides(explode(",", $liste));
	} else {
		$new = false;
		// compat au moment d'une migration depuis version anterieure
		// si pas de dir_type, alors c'est _DIR_PLUGINS
		foreach ($liste as $prefix => $infos) {
			if (!isset($infos['dir_type'])) {
				$liste[$prefix]['dir_type'] = "_DIR_PLUGINS";
				$new = true;
			}
		}
	}
	if ($new) {
		ecrire_meta('plugin', serialize($liste));
	}

	return $liste;
}
