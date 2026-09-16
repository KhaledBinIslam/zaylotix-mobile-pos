package com.zaylotix.pos;

import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.os.Message;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;
import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebChromeClient;

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

        // The web app sends every WhatsApp memo via `window.open('https://wa.me/...',
        // '_blank')` (Pos/Index.vue, Home.vue, Customers/Index.vue, etc.) — a plain
        // Android WebView drops window.open() entirely by default (no new tab, no
        // external-app handoff, nothing) unless the host app opts in via
        // setSupportMultipleWindows + a WebChromeClient.onCreateWindow override, which
        // this app never had. That's why tapping "Send via WhatsApp" did nothing in
        // the packaged app while working fine in a real mobile browser. Fixed by
        // catching the target URL through the standard hidden-WebView trick and
        // handing it to Android as a normal external Intent, so it opens WhatsApp
        // (or whatever app/browser can handle the link) the same way a real browser
        // tab would. Subclassing BridgeWebChromeClient (not replacing it) keeps every
        // other behavior — camera/mic permission prompts, file choosers, JS
        // dialogs — exactly as Capacitor already handles them.
        WebView webView = getBridge().getWebView();
        webView.getSettings().setSupportMultipleWindows(true);
        webView.getSettings().setJavaScriptCanOpenWindowsAutomatically(true);
        webView.setWebChromeClient(new ExternalLinkWebChromeClient(getBridge()));
    }

    private static class ExternalLinkWebChromeClient extends BridgeWebChromeClient {
        private final Context context;

        ExternalLinkWebChromeClient(Bridge bridge) {
            super(bridge);
            this.context = bridge.getContext();
        }

        @Override
        public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, Message resultMsg) {
            WebView hiddenWebView = new WebView(view.getContext());
            hiddenWebView.setWebViewClient(new WebViewClient() {
                @Override
                public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest request) {
                    launchExternally(request.getUrl().toString());
                    return true;
                }
            });
            WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
            transport.setWebView(hiddenWebView);
            resultMsg.sendToTarget();
            return true;
        }

        private void launchExternally(String url) {
            try {
                context.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            } catch (ActivityNotFoundException e) {
                // e.g. WhatsApp not installed on this device — same failure a real
                // browser tab would hit, so surface it instead of doing nothing.
                Toast.makeText(context, "কোনো অ্যাপ দিয়ে এই লিংক খোলা গেল না।", Toast.LENGTH_SHORT).show();
            }
        }
    }
}
