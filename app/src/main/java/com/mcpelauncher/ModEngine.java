package com.mcpelauncher;

import android.content.Context;
import android.util.Log;

import org.mozilla.javascript.Function;
import org.mozilla.javascript.Scriptable;
import org.mozilla.javascript.ScriptableObject;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class ModEngine {
    private static final String TAG = "ModEngine";

    static {
        try {
            System.loadLibrary("minecraftpe");
            Log.i(TAG, "Loaded libminecraftpe.so in process");
        } catch (UnsatisfiedLinkError e) {
            Log.w(TAG, "libminecraftpe.so not found in APK native libs, attempting fallback", e);
        }
        try {
            System.loadLibrary("modloader");
        } catch (UnsatisfiedLinkError e) {
            Log.e(TAG, "Failed to load modloader native library", e);
        }
    }

    private Context mContext;
    private org.mozilla.javascript.Context mRhinoContext;
    private Scriptable mScope;
    private boolean mNativeInitialized = false;
    private Listener mListener;

    private long mLocalPlayerEnt = 1001L;
    private Map<Long, float[]> mEntityPositions = new HashMap<>();

    public interface Listener {
        void onClientMessage(String message);
    }

    public ModEngine(Context context, Listener listener) {
        mContext = context;
        mListener = listener;
        ModPEApi.setModEngine(this);
        initNative();
        initRhino();
    }

    private void initNative() {
        try {
            nativeRegisterEngine();
            mNativeInitialized = nativeInitHooks();
            Log.i(TAG, "Native hooks initialized: " + mNativeInitialized);
        } catch (Throwable t) {
            Log.e(TAG, "Native initialization error", t);
        }
    }

    private void initRhino() {
        try {
            mRhinoContext = org.mozilla.javascript.Context.enter();
            mRhinoContext.setOptimizationLevel(-1);
            mScope = mRhinoContext.initStandardObjects();

            // Bind top-level functions
            bindFunction("clientMessage", ModPEApi.class.getMethod("clientMessage", String.class));
            bindFunction("preventDefault", ModPEApi.class.getMethod("preventDefault"));
            bindFunction("getPlayerEnt", ModPEApi.class.getMethod("getPlayerEnt"));
            bindFunction("getPlayerX", ModPEApi.class.getMethod("getPlayerX"));
            bindFunction("getPlayerY", ModPEApi.class.getMethod("getPlayerY"));
            bindFunction("getPlayerZ", ModPEApi.class.getMethod("getPlayerZ"));

            // Bind Server object
            ScriptableObject serverObj = new ScriptableObject() {
                @Override
                public String getClassName() {
                    return "Server";
                }
            };
            serverObj.defineFunctionProperties(new String[]{"getAllPlayers"}, ModPEApi.class, ScriptableObject.DONTENUM);
            mScope.put("Server", mScope, serverObj);

            // Bind Entity object
            ScriptableObject entityObj = new ScriptableObject() {
                @Override
                public String getClassName() {
                    return "Entity";
                }
            };
            entityObj.defineFunctionProperties(new String[]{"getAll", "getX", "getY", "getZ", "setCollisionSize"}, ModPEApi.class, ScriptableObject.DONTENUM);
            mScope.put("Entity", mScope, entityObj);

            Log.i(TAG, "Rhino JS engine initialized successfully");
        } catch (Throwable t) {
            Log.e(TAG, "Failed to initialize Rhino JS engine", t);
        } finally {
            org.mozilla.javascript.Context.exit();
        }
    }

    private void bindFunction(String name, java.lang.reflect.Method method) {
        try {
            org.mozilla.javascript.FunctionObject fo = new org.mozilla.javascript.FunctionObject(name, method, mScope);
            mScope.put(name, mScope, fo);
        } catch (Throwable t) {
            Log.e(TAG, "Error binding function: " + name, t);
        }
    }

    public void loadScriptString(String scriptCode, String scriptName) {
        org.mozilla.javascript.Context cx = org.mozilla.javascript.Context.enter();
        try {
            cx.setOptimizationLevel(-1);
            cx.evaluateString(mScope, scriptCode, scriptName, 1, null);
            Log.i(TAG, "Successfully loaded script: " + scriptName);
        } catch (Throwable t) {
            Log.e(TAG, "Error evaluating script: " + scriptName, t);
        } finally {
            org.mozilla.javascript.Context.exit();
        }
    }

    public void loadScriptFile(File file) {
        if (!file.exists()) return;
        StringBuilder sb = new StringBuilder();
        try (BufferedReader reader = new BufferedReader(new FileReader(file))) {
            String line;
            while ((line = reader.readLine()) != null) {
                sb.append(line).append("\n");
            }
            loadScriptString(sb.toString(), file.getName());
        } catch (Throwable t) {
            Log.e(TAG, "Error reading script file: " + file.getAbsolutePath(), t);
        }
    }

    public void callHook(String hookName, Object... args) {
        if (mScope == null) return;
        org.mozilla.javascript.Context cx = org.mozilla.javascript.Context.enter();
        try {
            cx.setOptimizationLevel(-1);
            Object funcObj = mScope.get(hookName, mScope);
            if (funcObj instanceof Function) {
                Function func = (Function) funcObj;
                func.call(cx, mScope, mScope, args);
            }
        } catch (Throwable t) {
            Log.e(TAG, "Error calling hook: " + hookName, t);
        } finally {
            org.mozilla.javascript.Context.exit();
        }
    }

    public void onModTick() {
        callHook("modTick");
    }

    public boolean onChatHook(String text) {
        ModPEApi.isPreventDefaultAndReset();
        callHook("chatHook", text);
        return ModPEApi.isPreventDefaultAndReset();
    }

    public void onNewLevel() {
        callHook("newLevel");
    }

    public void onLeaveGame() {
        callHook("leaveGame");
    }

    public void onAttackHook(long attacker, long victim) {
        callHook("attackHook", attacker, victim);
    }

    public void onClientMessage(String msg) {
        if (mListener != null) {
            mListener.onClientMessage(msg);
        }
    }

    public long getLocalPlayerEnt() {
        return mLocalPlayerEnt;
    }

    public Object[] getAllPlayers() {
        List<Long> players = new ArrayList<>();
        players.add(mLocalPlayerEnt);
        for (Long id : mEntityPositions.keySet()) {
            if (!id.equals(mLocalPlayerEnt)) {
                players.add(id);
            }
        }
        return players.toArray();
    }

    public Object[] getAllEntities() {
        return getAllPlayers();
    }

    public float getEntityX(long ptr) {
        if (mNativeInitialized) {
            return nativeGetEntityX(ptr);
        }
        float[] pos = mEntityPositions.get(ptr);
        return pos != null ? pos[0] : 0.0f;
    }

    public float getEntityY(long ptr) {
        if (mNativeInitialized) {
            return nativeGetEntityY(ptr);
        }
        float[] pos = mEntityPositions.get(ptr);
        return pos != null ? pos[1] : 64.0f;
    }

    public float getEntityZ(long ptr) {
        if (mNativeInitialized) {
            return nativeGetEntityZ(ptr);
        }
        float[] pos = mEntityPositions.get(ptr);
        return pos != null ? pos[2] : 0.0f;
    }

    public void setCollisionSize(long ptr, float width, float height) {
        if (mNativeInitialized) {
            nativeSetEntitySize(ptr, width, height);
        }
        Log.i(TAG, "Set collision size for entity " + ptr + " to " + width + "x" + height);
    }

    public void addMockEntity(long id, float x, float y, float z) {
        mEntityPositions.put(id, new float[]{x, y, z});
    }

    // Native method signatures
    private native void nativeRegisterEngine();
    private native boolean nativeInitHooks();
    private native void nativeSetEntitySize(long entityPtr, float width, float height);
    private native float nativeGetEntityX(long entityPtr);
    private native float nativeGetEntityY(long entityPtr);
    private native float nativeGetEntityZ(long entityPtr);
}
