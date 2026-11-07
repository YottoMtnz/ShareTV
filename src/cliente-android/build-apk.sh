#!/bin/bash
# ShareTV (cliente WebView) — pipeline manual sin Gradle, como F.Movil.
# Cero librerías: solo android.jar. APK mínimo para cajitas lentas.
set -e
PROJ=$HOME/workspace/sharetv/app-android
SDK=$HOME/workspace/tools/android-sdk
JDK=$HOME/workspace/tools/jdk17
BT=$SDK/build-tools/34.0.0
AJAR=$SDK/platforms/android-34/android.jar
WORK=$HOME/workspace/tools/sharetv-build
export JAVA_HOME=$JDK
export PATH=$JDK/bin:$PATH
rm -rf $WORK; mkdir -p $WORK/gen $WORK/classes $WORK/dex $WORK/stage
echo "== 1. Recursos =="
$BT/aapt2 compile --dir $PROJ/app/src/main/res -o $WORK/res.zip
$BT/aapt2 link -o $WORK/base.apk -I $AJAR \
  --manifest $PROJ/app/src/main/AndroidManifest.xml \
  --min-sdk-version 24 --target-sdk-version 34 --version-code 10 --version-name 1.9 \
  --java $WORK/gen $WORK/res.zip
echo "== 2. Java =="
RJAVA=$(find $WORK/gen -name "R.java")
SOURCES=$(find $PROJ/app/src/main/java -name "*.java")
$JDK/bin/javac -encoding UTF-8 -classpath $AJAR -source 17 -target 17 -d $WORK/classes $SOURCES "$RJAVA"
echo "== 3. Dex =="
(cd $WORK/classes && $JDK/bin/jar cf $WORK/classes.jar .)
$BT/d8 --release --min-api 24 --lib $AJAR --output $WORK/dex $WORK/classes.jar
echo "== 4. Empaquetar, alinear, firmar =="
cp $WORK/base.apk $WORK/stage/app-unsigned.apk
cp $WORK/dex/classes.dex $WORK/stage/classes.dex
(cd $WORK/stage && zip -q app-unsigned.apk classes.dex)
$BT/zipalign -p 4 $WORK/stage/app-unsigned.apk $WORK/app-aligned.apk
$BT/apksigner sign --ks $HOME/workspace/robocito-app/robocito.keystore --ks-pass pass:robocito --key-pass pass:robocito \
  --out $WORK/ShareTV.apk $WORK/app-aligned.apk
echo "== 5. Verificacion =="
$BT/apksigner verify --print-certs $WORK/ShareTV.apk | head -2
$BT/aapt dump badging $WORK/ShareTV.apk | head -6
cp $WORK/ShareTV.apk $HOME/workspace/your_files/ShareTV.apk
ls -la $HOME/workspace/your_files/ShareTV.apk
