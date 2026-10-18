package com.mcpelauncher;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.util.Log;
import android.view.View;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ListView;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;

public class MainActivity extends AppCompatActivity implements ModEngine.Listener {
    private static final String TAG = "MainActivity";

    private ModEngine mModEngine;
    private TextView mLogTextView;
    private ScrollView mLogScrollView;
    private EditText mChatEditText;
    private Button mSendChatButton;
    private Button mLaunchMcpeButton;
    private Button mImportScriptButton;
    private ListView mScriptListView;

    private List<String> mScriptNames = new ArrayList<>();
    private ArrayAdapter<String> mScriptAdapter;
    private Handler mHandler = new Handler(Looper.getMainLooper());

    private final ActivityResultLauncher<String[]> mFilePickerLauncher =
            registerForActivityResult(new ActivityResultContracts.OpenDocument(), this::onFilePicked);

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        mLogTextView = findViewById(R.id.logTextView);
        mLogScrollView = findViewById(R.id.logScrollView);
        mChatEditText = findViewById(R.id.chatEditText);
        mSendChatButton = findViewById(R.id.sendChatButton);
        mLaunchMcpeButton = findViewById(R.id.launchMcpeButton);
        mImportScriptButton = findViewById(R.id.importScriptButton);
        mScriptListView = findViewById(R.id.scriptListView);

        mScriptAdapter = new ArrayAdapter<>(this, android.R.layout.simple_list_item_1, mScriptNames);
        mScriptListView.setAdapter(mScriptAdapter);

        mModEngine = new ModEngine(this, this);

        copyDefaultAssets();
        loadExternalScripts();

        mSendChatButton.setOnClickListener(v -> {
            String text = mChatEditText.getText().toString().trim();
            if (!text.isEmpty()) {
                mChatEditText.setText("");
                log("Chat -> " + text);
                boolean prevented = mModEngine.onChatHook(text);
                if (prevented) {
                    log("[Hook] Chat command processed / prevented");
                }
            }
        });

        mLaunchMcpeButton.setOnClickListener(v -> launchMinecraft());

        mImportScriptButton.setOnClickListener(v -> {
            mFilePickerLauncher.launch(new String[]{"*/*"});
        });

        mModEngine.onNewLevel();
    }

    private void copyDefaultAssets() {
        File modsDir = getExternalFilesDir("mods");
        if (modsDir != null && !modsDir.exists()) {
            modsDir.mkdirs();
        }
        try {
            String[] assets = getAssets().list("mods");
            if (assets != null) {
                for (String asset : assets) {
                    File dest = new File(modsDir, asset);
                    if (!dest.exists()) {
                        try (InputStream in = getAssets().open("mods/" + asset);
                             OutputStream out = new FileOutputStream(dest)) {
                            byte[] buffer = new byte[4096];
                            int read;
                            while ((read = in.read(buffer)) != -1) {
                                out.write(buffer, 0, read);
                            }
                            log("Copied default asset script: " + asset);
                        }
                    }
                }
            }
        } catch (Throwable t) {
            Log.e(TAG, "Failed copying default asset scripts", t);
        }
    }

    private void loadExternalScripts() {
        File modsDir = getExternalFilesDir("mods");
        if (modsDir == null) return;
        File[] files = modsDir.listFiles();
        mScriptNames.clear();
        if (files != null) {
            for (File file : files) {
                if (file.isFile() && (file.getName().endsWith(".xml") || file.getName().endsWith(".js"))) {
                    mScriptNames.add(file.getName());
                    mModEngine.loadScriptFile(file);
                    log("Loaded script file: " + file.getName());
                }
            }
        }
        mScriptAdapter.notifyDataSetChanged();
    }

    private void onFilePicked(Uri uri) {
        if (uri == null) return;
        try {
            File modsDir = getExternalFilesDir("mods");
            String fileName = "imported_script_" + System.currentTimeMillis() + ".xml";
            File dest = new File(modsDir, fileName);
            try (InputStream in = getContentResolver().openInputStream(uri);
                 OutputStream out = new FileOutputStream(dest)) {
                byte[] buffer = new byte[4096];
                int read;
                while ((read = in.read(buffer)) != -1) {
                    out.write(buffer, 0, read);
                }
            }
            log("Imported script: " + fileName);
            loadExternalScripts();
        } catch (Throwable t) {
            Log.e(TAG, "Error importing script file", t);
            Toast.makeText(this, "Failed to import script", Toast.LENGTH_SHORT).show();
        }
    }

    private void launchMinecraft() {
        Intent intent = getPackageManager().getLaunchIntentForPackage("com.mojang.minecraftpe");
        if (intent != null) {
            log("Launching com.mojang.minecraftpe...");
            startActivity(intent);
        } else {
            log("com.mojang.minecraftpe is not installed on this device.");
            Toast.makeText(this, "Minecraft PE not found!", Toast.LENGTH_SHORT).show();
        }
    }

    private void log(String msg) {
        mHandler.post(() -> {
            mLogTextView.append(msg + "\n");
            mLogScrollView.post(() -> mLogScrollView.fullScroll(ScrollView.FOCUS_DOWN));
        });
    }

    @Override
    public void onClientMessage(String message) {
        log(message);
    }
}
