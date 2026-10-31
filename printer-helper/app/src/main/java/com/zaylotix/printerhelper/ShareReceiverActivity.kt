package com.zaylotix.printerhelper

import android.content.Intent
import android.graphics.BitmapFactory
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.widget.Button
import android.widget.ProgressBar
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject

/**
 * Phase 43 (docs/BT_PRINT_PLAN.md বিকল্প ক) — receives label images shared
 * from Zaylotix's own web page via `navigator.share({ files, text })`.
 *
 * Each shared PNG is already rendered by the web side from the EXACT same
 * BarcodeLabelCard.vue component the browser/PC print path uses (Phase
 * 41's thermal-mode fixed-dot barcode sizing included) — this activity
 * never re-derives the label's visual layout, it only converts whatever
 * pixels it receives into TSPL bytes and writes them to the remembered
 * printer. One PNG per physical copy wanted (duplicated on the web side,
 * not a `copies` count) keeps both sides simple — see TsplImageEncoder.
 *
 * `text` (optional JSON: {"widthMm":38,"heightMm":25,"gapMm":2}) carries
 * the shop's own label_settings size so the printed physical size always
 * matches what Zaylotix itself is configured for; missing/unparseable
 * falls back to the 38×25mm default every shop starts on.
 */
class ShareReceiverActivity : AppCompatActivity() {

    private lateinit var progressSpinner: ProgressBar
    private lateinit var progressText: TextView
    private lateinit var closeBtn: Button

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_share)
        progressSpinner = findViewById(R.id.progressSpinner)
        progressText = findViewById(R.id.progressText)
        closeBtn = findViewById(R.id.closeBtn)
        closeBtn.setOnClickListener { finish() }

        val address = PrinterPrefs.savedAddress(this)
        if (address == null) {
            fail(getString(R.string.no_printer_selected))
            return
        }

        val uris = extractImageUris(intent)
        if (uris.isEmpty()) {
            fail(getString(R.string.no_images_received))
            return
        }

        val sizeMm = parseSizeMm(intent.getStringExtra(Intent.EXTRA_TEXT))
        progressText.text = getString(R.string.printing_in_progress)

        Thread { printBatch(address, uris, sizeMm) }.start()
    }

    private fun extractImageUris(intent: Intent): List<Uri> = when (intent.action) {
        Intent.ACTION_SEND -> {
            val single = if (Build.VERSION.SDK_INT >= 33) {
                intent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)
            } else {
                @Suppress("DEPRECATION") intent.getParcelableExtra(Intent.EXTRA_STREAM)
            }
            listOfNotNull(single)
        }
        Intent.ACTION_SEND_MULTIPLE -> {
            val list = if (Build.VERSION.SDK_INT >= 33) {
                intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM, Uri::class.java)
            } else {
                @Suppress("DEPRECATION") intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM)
            }
            list ?: emptyList()
        }
        else -> emptyList()
    }

    /**
     * `labelHeightPx`/`copies` describe a SHEET: one tall PNG holding
     * copies.size labels stacked, each exactly labelHeightPx rows tall,
     * label k to be printed copies[k] times. Both absent (the memo path,
     * and any older web build) means the whole image is one single label
     * — the original contract, unchanged.
     */
    data class LabelSizeMm(
        val width: Double,
        val height: Double,
        val gap: Double,
        val labelHeightPx: Int = 0,
        val copies: List<Int> = emptyList(),
    )

    private fun parseSizeMm(text: String?): LabelSizeMm {
        if (text.isNullOrBlank()) return LabelSizeMm(38.0, 25.0, 2.0)
        return try {
            val json = JSONObject(text)
            val copiesArray = json.optJSONArray("copies")
            val copies = buildList {
                for (i in 0 until (copiesArray?.length() ?: 0)) {
                    add(copiesArray!!.optInt(i, 1).coerceAtLeast(1))
                }
            }
            LabelSizeMm(
                width = json.optDouble("widthMm", 38.0),
                height = json.optDouble("heightMm", 25.0),
                gap = json.optDouble("gapMm", 2.0),
                labelHeightPx = json.optInt("labelHeightPx", 0),
                copies = copies,
            )
        } catch (e: Exception) {
            LabelSizeMm(38.0, 25.0, 2.0)
        }
    }

    /** How many labels a received bitmap holds and how tall each is — the arithmetic lives in LabelSheet, where it is unit tested. */
    private fun slicesOf(bitmap: android.graphics.Bitmap, sizeMm: LabelSizeMm): LabelSheet.Slices =
        LabelSheet.of(
            bitmapWidth = bitmap.width,
            bitmapHeight = bitmap.height,
            labelHeightPx = sizeMm.labelHeightPx,
            copiesCount = sizeMm.copies.size,
            widthMm = sizeMm.width,
            heightMm = sizeMm.height,
            gapMm = sizeMm.gap,
        )

    @Suppress("MissingPermission") // MainActivity already confirmed BLUETOOTH_CONNECT before a printer could ever be saved
    private fun printBatch(address: String, uris: List<Uri>, sizeMm: LabelSizeMm) {
        val adapter = android.bluetooth.BluetoothAdapter.getDefaultAdapter()
        val device = adapter?.getRemoteDevice(address)
        var succeeded = 0
        var attempted = 0
        var lastError: String? = null

        if (device == null) {
            lastError = "প্রিন্টার ডিভাইস পাওয়া যায়নি।"
        } else {
            // Live-tested on the XP-365B: printing the whole batch over one
            // held-open connection drifts out of sync with the physical
            // label boundaries after the first label (the printer doesn't
            // seem to honor the GAP command for re-sync mid-connection).
            // A fresh connect() per label — mirroring MainActivity's single
            // test print, which landed correctly far more often — gives the
            // printer a clean slate each time instead of compounding drift.
            for (uri in uris) {
                val bitmap = try {
                    contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it) }
                } catch (e: Exception) {
                    lastError = e.message ?: e.toString()
                    null
                }
                if (bitmap == null) {
                    lastError = lastError ?: "ছবি পড়া যায়নি।"
                    continue
                }

                val slices = slicesOf(bitmap, sizeMm)
                val sliceH = slices.height

                for (k in 0 until slices.count) {
                    attempted++
                    val copies = sizeMm.copies.getOrElse(k) { 1 }
                    runOnUiThread { progressText.text = "${getString(R.string.printing_in_progress)} (${k + 1}/${slices.count})" }
                    val printer = BluetoothPrinter()
                    try {
                        printer.connect(device)
                        val top = k * sliceH
                        val payload = TsplImageEncoder.buildSingleLabelPayload(
                            widthMm = sizeMm.width, heightMm = sizeMm.height, gapMm = sizeMm.gap,
                            widthPx = bitmap.width, heightPx = sliceH,
                            bitmapBytes = TsplImageEncoder.packBitmap(bitmap.width, sliceH) { x, y ->
                                // Luminance threshold at 170, not the "obvious" 128:
                                // small text arrives with grey anti-aliased edges
                                // after the web side's downscale, and a mid-grey
                                // cutoff dropped those pixels, printing thin fonts
                                // broken/faint (Khaled's live photo). The source is
                                // black-on-white only, so anything meaningfully
                                // darker than paper is ink.
                                val pixel = bitmap.getPixel(x, top + y)
                                val alpha = Color.alpha(pixel)
                                // transparent background counts as white/not-black
                                alpha > 32 && (Color.red(pixel) + Color.green(pixel) + Color.blue(pixel)) / 3 < 170
                            },
                            copies = copies,
                        )
                        printer.write(payload)
                        succeeded++
                    } catch (e: Exception) {
                        lastError = e.message ?: e.toString()
                    } finally {
                        printer.disconnect()
                    }
                    Thread.sleep(400) // let the printer finish feeding/cutting before the next connect()
                }
            }
        }

        runOnUiThread {
            progressSpinner.visibility = android.view.View.GONE
            closeBtn.visibility = android.view.View.VISIBLE
            val total = attempted.coerceAtLeast(uris.size)
            progressText.text = if (succeeded == total) {
                getString(R.string.print_success) + " ($succeeded/$total)"
            } else {
                getString(R.string.print_failed, "$succeeded/$total — ${lastError ?: ""}")
            }
        }
    }

    private fun fail(message: String) {
        progressSpinner.visibility = android.view.View.GONE
        progressText.text = message
        closeBtn.visibility = android.view.View.VISIBLE
    }
}
