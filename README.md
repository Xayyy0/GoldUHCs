# MCPELauncher - ModPE Loader for Android 16 (MCPE 0.15.10)

MCPELauncher is a BlockLauncher-style ModPE script engine and native loader project built for Android 16 (API 36) targeting Minecraft PE 0.15.10 (32-bit ARM `armeabi-v7a`).

## Requirements
* Android 7.0 (API 24) or higher (Target SDK 35 for Android 16 compatibility).
* 32-bit ARM (`armeabi-v7a`) application execution support on the host system.

## How to Install and Use

1. **Install the APK**:
   - Download `app-debug.apk` built from GitHub Actions artifacts or build using `./gradlew assembleDebug`.
   - Install the APK on your Android 16 device.

2. **Script Storage Location**:
   - App-specific external storage path:
     `/sdcard/Android/data/com.mcpelauncher/files/mods/`
   - Default bundled script: `Hitbox for MCPE 0.15.10.xml` (copied automatically on first launch).

3. **Importing Scripts**:
   - Open MCPELauncher.
   - Tap **Import Script** to open the Android System Storage Access Framework (SAF) document picker and select any `.xml` or `.js` ModPE script.

4. **Testing Commands and Hooks**:
   - Enter commands (e.g. `.hb` or `.hb 1.4 1.8`) into the **Chat Hook / Command Tester** field in the launcher interface to verify `chatHook` processing.
   - Tap **Launch MCPE** to start `com.mojang.minecraftpe`.

---

## Technical Architecture & Android 16 Security Analysis (Approach A vs Approach B)

### Approach A (Original BlockLauncher Style - Cross-App Context Loading)
BlockLauncher originally used `createPackageContext("com.mojang.minecraftpe", CONTEXT_INCLUDE_CODE | CONTEXT_IGNORE_SECURITY)` to load `libminecraftpe.so` from the installed game into its own launcher process.

*Why Approach A fails on Android 10+ / Android 16 without root:*
1. **SELinux Domain Isolation (`untrusted_app`)**: Modern Android strictly isolates application UID private directories (`/data/data/com.mojang.minecraftpe` and `/data/app/...`). Calling `dlopen()` on external application library paths returns `EACCES` (Permission denied).
2. **Code Loading Policy**: Android 10 (targetSdk 29+) enforces W^X constraints and throws `SecurityException` when an app attempts to load executable code from another package without matching signatures or shared UIDs.
3. **No Root Memory Injection**: Without root (`su`), process `com.mcpelauncher` cannot `ptrace` or modify memory of a separate `com.mojang.minecraftpe` process.

### Approach B (Standalone Patched / Embedded Native Loader)
To bypass Android 10+ inter-app security boundaries without root, the loader native shared object (`libmodloader.so`) and Java script engine execute inside the SAME process address space as `libminecraftpe.so` (embedded directly in a patched target APK).

#### Step-by-Step Approach B Repacking & Patching Guide:
1. **Extract Target APK**: Unpack `com.mojang.minecraftpe` (0.15.10) using APK Editor or `apktool`.
2. **Place Native Binaries**:
   - Place `libminecraftpe.so` (22.65 MB) into `assets/` or `lib/armeabi-v7a/`.
   - Place `libmodloader.so` (built from `app/src/main/cpp`) into `lib/armeabi-v7a/`.
3. **Merge Dex & Classes**: Inject launcher classes (`com.mcpelauncher.ModEngine`, `com.mcpelauncher.MainActivity`) into `classes.dex`.
4. **Re-sign & Install**: Sign the patched APK using `apksigner` or `uber-apk-signer` and install on Android 16.

*Hooking Mechanism:*
* Utilizes **Dobby** ARM32 Thumb-2 inline hooking framework.
* Target symbols in `libminecraftpe.so`:
  * `_ZN6Entity7setSizeEff` (`Entity::setSize(float w, float h)`) - Modifies entity collision width (offset `0xb4`) and height (offset `0xb8`).
  * `_ZN8GameMode6attackER6PlayerR6Entity` (`GameMode::attack`) - Triggers `attackHook(attacker, victim)`.
  * `_ZN8GameMode4tickEv` (`GameMode::tick`) - Triggers `modTick()`.

---

## ModPE API Implementation Summary

### Implemented Hooks
* `modTick()`
* `chatHook(text)`
* `newLevel()`
* `leaveGame()`
* `attackHook(attacker, victim)`

### Implemented API Functions
* `clientMessage(msg)`
* `preventDefault()`
* `getPlayerEnt()`
* `getPlayerX()`, `getPlayerY()`, `getPlayerZ()`
* `Server.getAllPlayers()`
* `Entity.getAll()`
* `Entity.getX(ent)`, `Entity.getY(ent)`, `Entity.getZ(ent)`
* `Entity.setCollisionSize(ent, width, height)`

---

## Status & Compatibility Matrix

| Feature | Status | Notes |
| :--- | :--- | :--- |
| Android 16 (API 36) APK Packaging | **Working** | targetSdk 35, minSdk 24 |
| Rhino JavaScript Engine | **Working** | Embeds Mozilla Rhino 1.7.15 |
| ModPE Script Loader & SAF Importer | **Working** | Scoped storage compatible |
| Default `Hitbox for MCPE 0.15.10.xml` | **Bundled** | Loaded from assets |
| ARM32 Thumb-2 Dobby Hooks | **Implemented** | `Entity::setSize`, `GameMode::attack`, `GameMode::tick` |
| Approach A (Separate Launcher) | **Blocked on Android 10+** | Requires root due to SELinux/UID isolation |
| 64-bit Only Devices | **Unsupported** | MCPE 0.15.10 is 32-bit ARM only |
| Physical Hardware Execution | **Untested** | Environment is headless Linux CI |
