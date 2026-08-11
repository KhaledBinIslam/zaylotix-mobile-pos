package com.zaylotix.pos;

import android.os.Bundle;
import android.webkit.WebSettings;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // This app is a thin WebView shell around a live, frequently-updated
        // site (pos.zaylotix.com) — every fix/feature must reach every shop
        // the instant it's deployed, with zero user-facing troubleshooting.
        // Android WebView's own HTTP cache can otherwise pin a shop's app to
        // a stale page/JS bundle from whenever it was first opened, and a
        // shop owner has no realistic way to "clear app cache" themselves
        // (that's exactly what forced this fix — see the session that added
        // this comment). LOAD_NO_CACHE makes every navigation go straight to
        // the network instead of ever trusting a cached response, so this
        // class of staleness can't happen again. Doesn't touch the separate
        // service-worker Cache API the web app itself uses for offline mode
        // (see public/sw.js) — that's a different layer, unaffected by this.
        getBridge().getWebView().getSettings().setCacheMode(WebSettings.LOAD_NO_CACHE);
    }
}
