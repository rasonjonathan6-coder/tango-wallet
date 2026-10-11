<?php

/**
 * Gestion du taux de change USDT -> MGA, basé sur Binance P2P (référence),
 * moins une marge fixe (par défaut 200 MGA / USDT).
 *
 * Le taux est mis en cache en base (table taux_change) pendant 10 minutes
 * pour ne pas dépendre de Binance à chaque clic client, et pour avoir
 * une valeur de secours si Binance P2P est indisponible au moment du retrait.
 *
 * SQL nécessaire (à exécuter une fois dans phpMyAdmin) :
 *
 * CREATE TABLE taux_change (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   paire VARCHAR(20) NOT NULL DEFAULT 'USDT_MGA',
 *   taux_binance DECIMAL(18,4) NOT NULL,
 *   marge DECIMAL(18,4) NOT NULL DEFAULT 200,
 *   taux_client DECIMAL(18,4) NOT NULL,
 *   updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 */

define("TAUX_MARGE_MGA", 200);      // à ajuster si besoin
define("TAUX_CACHE_MINUTES", 30);


// Récupère le prix moyen USDT/MGA sur Binance P2P (annonces "vente" de MGA
// par les marchands, càd le prix auquel on peut vendre nos USDT).
function fetchTauxBinanceP2P(){

    $url = "https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search";

    $payload = json_encode([
        "asset"          => "USDT",
        "fiat"           => "MGA",
        "tradeType"      => "SELL",   // on VEND nos USDT contre des MGA
        "page"           => 1,
        "rows"           => 5,
        "payTypes"       => []
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    curl_close($ch);

    if(!$response){
        return null;
    }

    $data = json_decode($response, true);

    if(empty($data['data'])){
        return null;
    }

    $prix = [];

    foreach($data['data'] as $ad){
        if(isset($ad['adv']['price'])){
            $prix[] = (float)$ad['adv']['price'];
        }
    }

    if(empty($prix)){
        return null;
    }

    // moyenne des meilleures annonces
    return array_sum($prix) / count($prix);

}


// Retourne ["taux_binance"=>.., "marge"=>.., "taux_client"=>..]
// taux_client = ce que le client reçoit réellement par USDT retiré
function getTauxActuel($pdo){

    $stmt = $pdo->query("
        SELECT * FROM taux_change
        WHERE paire='USDT_MGA'
        ORDER BY id DESC
        LIMIT 1
    ");

    $cache = $stmt->fetch(PDO::FETCH_ASSOC);

    $cacheValide = $cache && (strtotime($cache['updated_at']) > (time() - TAUX_CACHE_MINUTES * 60));

    if($cacheValide){
        return $cache;
    }

    $tauxBinance = fetchTauxBinanceP2P();

    if($tauxBinance === null){

        // Binance injoignable : on garde l'ancien taux en cache s'il existe
        if($cache){
            return $cache;
        }

        return null;
    }

    $tauxClient = $tauxBinance - TAUX_MARGE_MGA;

    $pdo->prepare("
        INSERT INTO taux_change (paire, taux_binance, marge, taux_client)
        VALUES ('USDT_MGA', ?, ?, ?)
    ")->execute([$tauxBinance, TAUX_MARGE_MGA, $tauxClient]);

    return [
        "taux_binance" => $tauxBinance,
        "marge"        => TAUX_MARGE_MGA,
        "taux_client"  => $tauxClient
    ];

}

?>
