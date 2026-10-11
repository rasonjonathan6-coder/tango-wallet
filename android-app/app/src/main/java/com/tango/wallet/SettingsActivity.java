package com.tango.wallet;

import android.content.SharedPreferences;
import android.os.Bundle;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

/**
 * Permet de changer l'URL de base de l'hébergement (par ex. IP du serveur,
 * nouveau domaine) sans recompiler l'application.
 */
public class SettingsActivity extends AppCompatActivity {

    public static final String PREFS = "wallet_prefs";
    public static final String KEY_URL = "base_url";

    // Hébergement par défaut de l'application.
    public static final String DEFAULT_URL =
            "https://work-2-cheyxecpzltibrus.prod-runtime.all-hands.dev";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_settings);

        EditText input = findViewById(R.id.input_url);
        Button save = findViewById(R.id.btn_save);

        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        input.setText(prefs.getString(KEY_URL, DEFAULT_URL));

        save.setOnClickListener(v -> {
            String url = input.getText().toString().trim();
            if (url.isEmpty()) {
                url = DEFAULT_URL;
            }
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                url = "https://" + url;
            }
            while (url.endsWith("/")) {
                url = url.substring(0, url.length() - 1);
            }
            prefs.edit().putString(KEY_URL, url).apply();
            Toast.makeText(this, R.string.settings_saved, Toast.LENGTH_SHORT).show();
            finish();
        });
    }
}
