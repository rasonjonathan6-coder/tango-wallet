package com.tango.wallet;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.splashscreen.SplashScreen;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.appbar.MaterialToolbar;
import com.google.android.material.button.MaterialButton;

/**
 * Affiche le portefeuille de l'utilisateur (wallet/portefeuille.php) dans une
 * WebView. Splash natif, pull-to-refresh, barre de progression, ecran d'erreur
 * avec reessai, telechargements et liens externes geres par le systeme.
 */
public class MainActivity extends AppCompatActivity {

    private WebView webView;
    private SwipeRefreshLayout swipeRefresh;
    private ProgressBar progressBar;
    private View errorView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // Splash natif (doit precede super.onCreate).
        SplashScreen.installSplashScreen(this);
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        MaterialToolbar toolbar = findViewById(R.id.toolbar);
        setSupportActionBar(toolbar);

        webView = findViewById(R.id.webView);
        swipeRefresh = findViewById(R.id.swipeRefresh);
        progressBar = findViewById(R.id.progressBar);
        errorView = findViewById(R.id.errorView);
        MaterialButton btnRetry = findViewById(R.id.btnRetry);

        swipeRefresh.setColorSchemeResources(R.color.wallet_primary);
        swipeRefresh.setOnRefreshListener(() -> {
            errorView.setVisibility(View.GONE);
            webView.reload();
        });
        btnRetry.setOnClickListener(v -> {
            errorView.setVisibility(View.GONE);
            loadPortefeuille();
        });

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setBuiltInZoomControls(false);
        settings.setUserAgentString(settings.getUserAgentString() + " MonAppAndroid/1.2");

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return handleExternal(request.getUrl());
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                progressBar.setVisibility(View.VISIBLE);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                progressBar.setVisibility(View.GONE);
                swipeRefresh.setRefreshing(false);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                super.onReceivedError(view, request, error);
                // Seule l'erreur de la ressource principale declenche l'ecran d'erreur.
                if (request.isForMainFrame()) {
                    progressBar.setVisibility(View.GONE);
                    swipeRefresh.setRefreshing(false);
                    errorView.setVisibility(View.VISIBLE);
                }
            }
        });

        // Ouvre les fichiers telecharges (APK, PDF...) avec le gestionnaire systeme.
        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition,
                                        String mimeType, long contentLength) {
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Impossible d'ouvrir le lien", Toast.LENGTH_SHORT).show();
                }
            }
        });

        loadPortefeuille();
    }

    private String baseUrl() {
        SharedPreferences prefs = getSharedPreferences(SettingsActivity.PREFS, MODE_PRIVATE);
        return prefs.getString(SettingsActivity.KEY_URL, SettingsActivity.DEFAULT_URL);
    }

    private void loadPortefeuille() {
        String base = baseUrl();
        if (!base.endsWith("/")) base = base + "/";
        webView.loadUrl(base + "wallet/portefeuille.php");
    }

    /**
     * Ouvre les liens externes (mailto, tel, autres domaines) dans l'application
     * systeme ; garde la navigation interne dans la WebView.
     */
    private boolean handleExternal(Uri uri) {
        String scheme = uri.getScheme() == null ? "" : uri.getScheme();
        if (scheme.equals("http") || scheme.equals("https")) {
            String host = uri.getHost() == null ? "" : uri.getHost();
            String baseHost = Uri.parse(baseUrl()).getHost();
            if (host != null && host.equals(baseHost)) {
                return false;
            }
        }
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (Exception e) {
            Toast.makeText(this, "Impossible d'ouvrir le lien", Toast.LENGTH_SHORT).show();
        }
        return true;
    }

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, 1, 0, getString(R.string.menu_refresh));
        menu.add(0, 2, 1, getString(R.string.menu_settings));
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        if (item.getItemId() == 1) {
            loadPortefeuille();
            return true;
        }
        if (item.getItemId() == 2) {
            startActivity(new Intent(this, SettingsActivity.class));
            return true;
        }
        return super.onOptionsItemSelected(item);
    }

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null && webView.getUrl() == null) {
            loadPortefeuille();
        }
    }
}
