#include <jni.h>
#include <android/log.h>
#include <dlfcn.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include "dobby.h"

#define LOG_TAG "MCPELauncherNative"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static JavaVM* g_vm = nullptr;
static jobject g_modEngineObj = nullptr;
static jclass g_modEngineClass = nullptr;
static jmethodID g_midOnModTick = nullptr;
static jmethodID g_midOnAttackHook = nullptr;

typedef void (*Entity_setSize_t)(void* entity, float w, float h);
static Entity_setSize_t g_orig_Entity_setSize = nullptr;

typedef void (*GameMode_attack_t)(void* gameMode, void* player, void* entity);
static GameMode_attack_t g_orig_GameMode_attack = nullptr;

typedef void (*GameMode_tick_t)(void* gameMode);
static GameMode_tick_t g_orig_GameMode_tick = nullptr;

// Entity setSize Hook
void hooked_Entity_setSize(void* entity, float w, float h) {
    if (entity != nullptr) {
        // Entity width and height offset 0xb4 and 0xb8 in MCPE 0.15.10
        float* pWidth = (float*)((uintptr_t)entity + 0xb4);
        float* pHeight = (float*)((uintptr_t)entity + 0xb8);
        *pWidth = w;
        *pHeight = h;
    }
    if (g_orig_Entity_setSize) {
        g_orig_Entity_setSize(entity, w, h);
    }
}

// GameMode attack Hook
void hooked_GameMode_attack(void* gameMode, void* player, void* entity) {
    if (g_vm && g_modEngineObj && g_midOnAttackHook) {
        JNIEnv* env = nullptr;
        bool attached = false;
        if (g_vm->GetEnv((void**)&env, JNI_VERSION_1_6) != JNI_OK) {
            g_vm->AttachCurrentThread(&env, nullptr);
            attached = true;
        }
        if (env) {
            env->CallVoidMethod(g_modEngineObj, g_midOnAttackHook, (jlong)player, (jlong)entity);
        }
        if (attached) {
            g_vm->DetachCurrentThread();
        }
    }
    if (g_orig_GameMode_attack) {
        g_orig_GameMode_attack(gameMode, player, entity);
    }
}

// GameMode tick Hook
void hooked_GameMode_tick(void* gameMode) {
    if (g_orig_GameMode_tick) {
        g_orig_GameMode_tick(gameMode);
    }
    if (g_vm && g_modEngineObj && g_midOnModTick) {
        JNIEnv* env = nullptr;
        bool attached = false;
        if (g_vm->GetEnv((void**)&env, JNI_VERSION_1_6) != JNI_OK) {
            g_vm->AttachCurrentThread(&env, nullptr);
            attached = true;
        }
        if (env) {
            env->CallVoidMethod(g_modEngineObj, g_midOnModTick);
        }
        if (attached) {
            g_vm->DetachCurrentThread();
        }
    }
}

extern "C" JNIEXPORT jint JNICALL JNI_OnLoad(JavaVM* vm, void* reserved) {
    g_vm = vm;
    LOGI("JNI_OnLoad initialized");
    return JNI_VERSION_1_6;
}

extern "C" JNIEXPORT void JNICALL
Java_com_mcpelauncher_ModEngine_nativeRegisterEngine(JNIEnv* env, jobject thiz) {
    if (g_modEngineObj) {
        env->DeleteGlobalRef(g_modEngineObj);
    }
    g_modEngineObj = env->NewGlobalRef(thiz);
    jclass clazz = env->GetObjectClass(thiz);
    g_modEngineClass = (jclass)env->NewGlobalRef(clazz);
    g_midOnModTick = env->GetMethodID(g_modEngineClass, "onModTick", "()V");
    g_midOnAttackHook = env->GetMethodID(g_modEngineClass, "onAttackHook", "(JJ)V");
    LOGI("ModEngine registered with native loader");
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_mcpelauncher_ModEngine_nativeInitHooks(JNIEnv* env, jobject thiz) {
    void* handle = dlopen("libminecraftpe.so", RTLD_NOW | RTLD_GLOBAL);
    if (!handle) {
        LOGE("libminecraftpe.so not loaded in current process space: %s", dlerror());
        LOGI("Notice: Approach B (patched APK containing libminecraftpe.so) is required on Android 16 for in-process symbol hooking.");
        return JNI_FALSE;
    }

    // Resolve symbol _ZN6Entity7setSizeEff
    void* pSetSize = dlsym(handle, "_ZN6Entity7setSizeEff");
    if (pSetSize) {
        DobbyHook(pSetSize, (void*)hooked_Entity_setSize, (void**)&g_orig_Entity_setSize);
        LOGI("Hooked _ZN6Entity7setSizeEff at %p", pSetSize);
    } else {
        LOGE("Failed to find symbol _ZN6Entity7setSizeEff");
    }

    // Resolve symbol _ZN8GameMode6attackER6PlayerR6Entity
    void* pAttack = dlsym(handle, "_ZN8GameMode6attackER6PlayerR6Entity");
    if (pAttack) {
        DobbyHook(pAttack, (void*)hooked_GameMode_attack, (void**)&g_orig_GameMode_attack);
        LOGI("Hooked _ZN8GameMode6attackER6PlayerR6Entity at %p", pAttack);
    } else {
        LOGE("Failed to find symbol _ZN8GameMode6attackER6PlayerR6Entity");
    }

    // Resolve symbol _ZN8GameMode4tickEv
    void* pTick = dlsym(handle, "_ZN8GameMode4tickEv");
    if (pTick) {
        DobbyHook(pTick, (void*)hooked_GameMode_tick, (void**)&g_orig_GameMode_tick);
        LOGI("Hooked _ZN8GameMode4tickEv at %p", pTick);
    } else {
        LOGE("Failed to find symbol _ZN8GameMode4tickEv");
    }

    return JNI_TRUE;
}

extern "C" JNIEXPORT void JNICALL
Java_com_mcpelauncher_ModEngine_nativeSetEntitySize(JNIEnv* env, jobject thiz, jlong entityPtr, jfloat w, jfloat h) {
    void* entity = (void*)entityPtr;
    if (entity != nullptr) {
        float* pWidth = (float*)((uintptr_t)entity + 0xb4);
        float* pHeight = (float*)((uintptr_t)entity + 0xb8);
        *pWidth = w;
        *pHeight = h;
        if (g_orig_Entity_setSize) {
            g_orig_Entity_setSize(entity, w, h);
        }
    }
}

extern "C" JNIEXPORT jfloat JNICALL
Java_com_mcpelauncher_ModEngine_nativeGetEntityX(JNIEnv* env, jobject thiz, jlong entityPtr) {
    if (entityPtr == 0) return 0.0f;
    float* pX = (float*)((uintptr_t)entityPtr + 0x1c);
    return *pX;
}

extern "C" JNIEXPORT jfloat JNICALL
Java_com_mcpelauncher_ModEngine_nativeGetEntityY(JNIEnv* env, jobject thiz, jlong entityPtr) {
    if (entityPtr == 0) return 0.0f;
    float* pY = (float*)((uintptr_t)entityPtr + 0x20);
    return *pY;
}

extern "C" JNIEXPORT jfloat JNICALL
Java_com_mcpelauncher_ModEngine_nativeGetEntityZ(JNIEnv* env, jobject thiz, jlong entityPtr) {
    if (entityPtr == 0) return 0.0f;
    float* pZ = (float*)((uintptr_t)entityPtr + 0x24);
    return *pZ;
}
