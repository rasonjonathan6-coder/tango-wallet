# Tango Wallet — application Android

Application Android (WebView) qui affiche le portefeuille Tango Wallet, avec une
enveloppe native soignée : Material 3, splash natif, pull-to-refresh, barre de
progression, écran hors-ligne avec réessai et icône adaptative.

## Identifiants
- Package : `com.tango.wallet`
- Écran principal : `MainActivity` (charge `<base>/wallet/portefeuille.php`)
- User-Agent envoyé : `... MonAppAndroid/1.2` (l'app serveur reconnaît le mode WebView et sa version)
- Écran de réglage : `SettingsActivity` (change l'URL d'hébergement)

## Fonctionnalités de l'enveloppe native
- Splash screen natif (`androidx.core:core-splashscreen`).
- `MaterialToolbar` + thème `Theme.Material3.Light` aux couleurs du portefeuille web (accent teal `#0EA5A4`).
- `SwipeRefreshLayout` (tirer pour rafraîchir) et barre de progression fine en haut.
- Écran « Connexion impossible » avec bouton **Réessayer** en cas d'échec réseau.
- Liens externes (`mailto:`, `tel:`, autres domaines) et téléchargements (APK, PDF) ouverts par le système.
- Icône adaptative (fond teal + symbole ₮) déclinée pour toutes les densités.
- Trafic en clair (HTTP) limité aux hôtes de développement locaux ; HTTPS obligatoire ailleurs.

## Compilation
Pré-requis : JDK 17+ (testé avec JDK 21), Android SDK (platform 34, build-tools 34.0.0), Gradle 8.9.

```bash
echo "sdk.dir=/chemin/vers/Android/sdk" > local.properties
export TANGO_KEYSTORE_PASSWORD=...   # mot de passe du keystore
export TANGO_KEY_PASSWORD=...        # mot de passe de la clé
gradle :app:assembleDebug            # APK de test
gradle :app:assembleRelease          # APK signé  -> app/build/outputs/apk/release/app-release.apk
gradle :app:bundleRelease            # AAB signé  -> app/build/outputs/bundle/release/app-release.aab
```

## Version de production / Play Store
1. Créer un keystore :
   `keytool -genkey -v -keystore wallet-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias wallet`
2. Renseigner les mots de passe via `TANGO_KEYSTORE_PASSWORD` / `TANGO_KEY_PASSWORD` (jamais commités).
3. Construire l'AAB : `gradle :app:bundleRelease`.
4. Dans l'app, mettre l'URL d'hébergement sur le domaine HTTPS de production.
5. Téléverser l'AAB (`app-release.aab`) dans la Play Console. Google Play re-signe
   l'application avec la clé de signature Play ; conserver le keystore d'upload.

> L'APK servi par l'app web (`?download=apk`) correspond à `downloads/tango-wallet.apk`.
> Après un nouveau build, copier `app-release.apk` vers `downloads/tango-wallet.apk`
> pour que la mise à jour in-app pointe sur la bonne version.
