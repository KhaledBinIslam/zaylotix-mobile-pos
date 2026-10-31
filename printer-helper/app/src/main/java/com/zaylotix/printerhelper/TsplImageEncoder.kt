package com.zaylotix.printerhelper

/**
 * Converts a monochrome pixel grid into a TSPL BITMAP command's binary
 * payload, and assembles the full SIZE/GAP/CLS/BITMAP/PRINT command
 * sequence for one label (docs/LABEL_PRINT_PLAN.md's dot-width math,
 * docs/BT_PRINT_PLAN.md অংশ ২).
 *
 * Deliberately pure — takes a plain `(x, y) -> Boolean` pixel predicate,
 * never an android.graphics.Bitmap directly, so this logic is unit-
 * testable on the plain JVM (src/test/) without any Android framework
 * dependency or instrumentation.
 */
object TsplImageEncoder {

    /** Bytes needed per row: each byte packs 8 horizontal pixels, MSB = leftmost pixel. */
    fun bytesPerRow(widthPx: Int): Int = (widthPx + 7) / 8

    /**
     * Packs a widthPx×heightPx monochrome image into TSPL's row-major
     * 1-bit-per-pixel byte layout. `isBlack(x, y)` returns true for a
     * pixel that should print as a dark dot.
     *
     * Bit polarity here is 0 = ink/black, 1 = blank/white — confirmed
     * against a live XP-365B print: the "obvious" TSPL-spec polarity
     * (1 = black) printed every label fully inverted (white text on a
     * solid black label). This clone firmware's BITMAP command reads the
     * opposite polarity, so the byte array starts all-0xFF (blank) and
     * black pixels clear their bit.
     */
    fun packBitmap(widthPx: Int, heightPx: Int, isBlack: (x: Int, y: Int) -> Boolean): ByteArray {
        val stride = bytesPerRow(widthPx)
        val out = ByteArray(stride * heightPx) { 0xFF.toByte() }
        for (y in 0 until heightPx) {
            for (x in 0 until widthPx) {
                if (isBlack(x, y)) {
                    val byteIndex = y * stride + x / 8
                    val bitIndex = 7 - (x % 8)
                    out[byteIndex] = (out[byteIndex].toInt() and (1 shl bitIndex).inv()).toByte()
                }
            }
        }
        return out
    }

    /**
     * Full TSPL command sequence for ONE label print: one bitmap, one
     * copy. Multiple physical copies are handled by the caller sending
     * this same payload again (or the web side including the same PNG
     * N times in the share) — deliberately no `copies` parameter here,
     * so this function has exactly one job and can't drift from what a
     * single `PRINT 1,1` actually does.
     */
    fun buildSingleLabelPayload(widthMm: Double, heightMm: Double, gapMm: Double, widthPx: Int, heightPx: Int, bitmapBytes: ByteArray, copies: Int = 1): ByteArray {
        val stride = bytesPerRow(widthPx)
        val header = buildString {
            append("SIZE ").append(fmtMm(widthMm)).append(" mm,").append(fmtMm(heightMm)).append(" mm\r\n")
            append("GAP ").append(fmtMm(gapMm)).append(" mm,0 mm\r\n")
            // SET TEAR ON — the tear bar sits ~14mm past the print head on
            // these printers; tear mode makes the printer BACKFEED to the
            // label's true start before printing and present the finished
            // label at the tear bar after. Without it (this clone firmware
            // doesn't default it on), printing starts wherever the head
            // happens to sit — mid-label — which is exactly the
            // "starts from half the sticker" behavior seen live on the
            // XP-365B.
            append("SET TEAR ON\r\n")
            // NO DENSITY/SPEED here, deliberately. They were added during
            // the faint-print emergency on the theory that the printer had
            // a density of 0 stored in its own memory — the real cause was
            // the label roll loaded upside down. Stating them made things
            // strictly worse: live on the XP-365B, a payload carrying
            // DENSITY 15 + SPEED 2 printed NOTHING AT ALL (the in-app test
            // print included, which goes through this same function), while
            // the identical payload without those two lines printed
            // correctly on the same printer, same roll, same minute. This
            // clone firmware evidently rejects the whole job rather than
            // just the line it doesn't like, so the proven-working header
            // is the one that says nothing about heat or speed and lets the
            // printer use its own panel settings.
            append("DIRECTION 0\r\n")
            append("CLS\r\n")
            append("BITMAP 0,0,").append(stride).append(",").append(heightPx).append(",0,")
        }
        val headerBytes = header.toByteArray(Charsets.US_ASCII)
        // PRINT <sets>,1 — TSPL's own repeat count, so N copies of the same
        // label are one job the printer repeats (with its own backfeed
        // between each) instead of N separate Bluetooth round trips.
        val footerBytes = "\r\nPRINT ${copies.coerceAtLeast(1)},1\r\n".toByteArray(Charsets.US_ASCII)
        return headerBytes + bitmapBytes + footerBytes
    }

    /**
     * TSPL's documented GAPDETECT command — feeds paper past the gap
     * sensor so the printer measures and stores the real physical
     * label+gap pitch itself, instead of trusting our own SIZE/GAP
     * numbers. No x,y arguments: per the TSPL2 spec, omitting them makes
     * the printer self-detect both the label length and the gap length
     * (same thing the XP-365B's power-on+feed-button calibration is
     * meant to do, but triggered from software instead of that button
     * combo — useful when the button procedure isn't working/known for
     * a given unit).
     */
    fun buildCalibrationPayload(widthMm: Double, heightMm: Double, gapMm: Double): ByteArray {
        val header = buildString {
            append("SIZE ").append(fmtMm(widthMm)).append(" mm,").append(fmtMm(heightMm)).append(" mm\r\n")
            append("GAP ").append(fmtMm(gapMm)).append(" mm,0 mm\r\n")
            append("GAPDETECT\r\n")
        }
        return header.toByteArray(Charsets.US_ASCII)
    }

    /** "38" not "38.0", but "38.5" kept as-is — TSPL accepts plain decimal mm values either way; this just keeps whole-number sizes readable in logs. */
    private fun fmtMm(mm: Double): String =
        if (mm == Math.floor(mm)) mm.toLong().toString() else mm.toString()
}
