package com.mcpelauncher;

import android.util.Log;

public class ModPEApi {
    private static final String TAG = "ModPEApi";
    private static ModEngine sModEngine;
    private static boolean sPreventDefault = false;

    public static void setModEngine(ModEngine engine) {
        sModEngine = engine;
    }

    public static void clientMessage(String message) {
        Log.i(TAG, "[ClientMessage] " + message);
        if (sModEngine != null) {
            sModEngine.onClientMessage(message);
        }
    }

    public static void preventDefault() {
        sPreventDefault = true;
    }

    public static boolean isPreventDefaultAndReset() {
        boolean prev = sPreventDefault;
        sPreventDefault = false;
        return prev;
    }

    public static long getPlayerEnt() {
        if (sModEngine != null) {
            return sModEngine.getLocalPlayerEnt();
        }
        return 1L;
    }

    public static float getPlayerX() {
        if (sModEngine != null) {
            return sModEngine.getEntityX(getPlayerEnt());
        }
        return 0.0f;
    }

    public static float getPlayerY() {
        if (sModEngine != null) {
            return sModEngine.getEntityY(getPlayerEnt());
        }
        return 64.0f;
    }

    public static float getPlayerZ() {
        if (sModEngine != null) {
            return sModEngine.getEntityZ(getPlayerEnt());
        }
        return 0.0f;
    }

    public static Object[] getAllPlayers() {
        if (sModEngine != null) {
            return sModEngine.getAllPlayers();
        }
        return new Object[]{1L};
    }

    public static Object[] getAll() {
        if (sModEngine != null) {
            return sModEngine.getAllEntities();
        }
        return new Object[]{1L};
    }

    public static float getX(Object ent) {
        long ptr = parseEntityPtr(ent);
        if (sModEngine != null) {
            return sModEngine.getEntityX(ptr);
        }
        return 0.0f;
    }

    public static float getY(Object ent) {
        long ptr = parseEntityPtr(ent);
        if (sModEngine != null) {
            return sModEngine.getEntityY(ptr);
        }
        return 0.0f;
    }

    public static float getZ(Object ent) {
        long ptr = parseEntityPtr(ent);
        if (sModEngine != null) {
            return sModEngine.getEntityZ(ptr);
        }
        return 0.0f;
    }

    public static void setCollisionSize(Object ent, double width, double height) {
        long ptr = parseEntityPtr(ent);
        if (sModEngine != null) {
            sModEngine.setCollisionSize(ptr, (float) width, (float) height);
        }
    }

    private static long parseEntityPtr(Object ent) {
        if (ent instanceof Number) {
            return ((Number) ent).longValue();
        }
        return 0L;
    }
}
