# Tango Wallet — application Android

Application Android (WebView) qui affiche le portefeuille Tango Wallet.

## Identifiants
- Package : `com.tango.wallet`
- Écran principal : `MainActivity` (charge `<base>/wallet/portefeuille.php`)
- User-Agent envoyé : `... MonAppAndroid/1.0` (l'app serveur reconnaît le mode WebView)
- Écran de réglage : `SettingsActivity` (change l'URL d'hébergement)

## Compilation
```bash
# Pré-requis : JDK 17+, Android SDK, Gradle
echo "sdk.dir=/chemin/vers/Android/sdk" > local.properties
gradle :app:assembleDebug
# APK : app/build/outputs/apk/debug/app-debug.apk
```

## Version de production
1. Créer un keystore :
   `keytool -genkey -v -keystore wallet-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias wallet`
2. Ajouter la signature dans `app/build.gradle`, puis `gradle :app:assembleRelease`.
3. Dans l'app, mettre l'URL d'hébergement sur le domaine HTTPS de production.
