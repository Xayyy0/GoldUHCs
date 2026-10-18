#ifndef DOBBY_H
#define DOBBY_H

#include <stdint.h>
#include <dlfcn.h>
#include <android/log.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef int (*DobbyHook_t)(void *function_address, void *replace_call, void **origin_call);

static inline int DobbyHook(void *function_address, void *replace_call, void **origin_call) {
    static DobbyHook_t pDobbyHook = nullptr;
    if (!pDobbyHook) {
        void* handle = dlopen("libdobby.so", RTLD_NOW | RTLD_GLOBAL);
        if (handle) {
            pDobbyHook = (DobbyHook_t)dlsym(handle, "DobbyHook");
        }
    }
    if (pDobbyHook) {
        return pDobbyHook(function_address, replace_call, origin_call);
    }
    __android_log_print(ANDROID_LOG_WARN, "MCPELauncher", "DobbyHook called but libdobby.so not present");
    return -1;
}

#ifdef __cplusplus
}
#endif

#endif // DOBBY_H
