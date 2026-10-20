#ifndef DOBBY_H
#define DOBBY_H

#include <stdint.h>
#include <dlfcn.h>
#include <sys/mman.h>
#include <unistd.h>
#include <string.h>
#include <android/log.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef int (*DobbyHook_t)(void *function_address, void *replace_call, void **origin_call);

static inline int DobbyHook(void *function_address, void *replace_call, void **origin_call) {
    if (!function_address || !replace_call) return -1;

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

    // Fallback Thumb-2 inline trampoline hooking for ARM32
    uintptr_t addr = (uintptr_t)function_address;
    uintptr_t target = (uintptr_t)replace_call;

    bool isThumb = (addr & 1) != 0;
    uintptr_t codeAddr = addr & ~1;

    long pageSize = sysconf(_SC_PAGE_SIZE);
    void* pageStart = (void*)(codeAddr & ~(pageSize - 1));
    if (mprotect(pageStart, pageSize, PROT_READ | PROT_WRITE | PROT_EXEC) != 0) {
        __android_log_print(ANDROID_LOG_ERROR, "MCPELauncher", "mprotect failed for address %p", (void*)codeAddr);
        return -1;
    }

    if (origin_call) {
        *origin_call = (void*)addr;
    }

    if (isThumb) {
        uint16_t trampoline[] = { 0xf8df, 0xf000 };
        memcpy((void*)codeAddr, trampoline, sizeof(trampoline));
        uint32_t* pTarget = (uint32_t*)(codeAddr + 4);
        *pTarget = target | 1;
    } else {
        uint32_t trampoline[] = { 0xe51ff004, (uint32_t)(target) };
        memcpy((void*)codeAddr, trampoline, sizeof(trampoline));
    }

    mprotect(pageStart, pageSize, PROT_READ | PROT_EXEC);
    __android_log_print(ANDROID_LOG_INFO, "MCPELauncher", "Applied fallback inline hook at %p -> %p", (void*)addr, (void*)target);
    return 0;
}

#ifdef __cplusplus
}
#endif

#endif // DOBBY_H
