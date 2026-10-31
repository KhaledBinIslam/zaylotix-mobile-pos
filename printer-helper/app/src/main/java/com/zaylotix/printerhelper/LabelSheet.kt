package com.zaylotix.printerhelper

import kotlin.math.roundToInt

/**
 * How many labels a received image holds, and how tall each one is.
 *
 * The web side stacks distinct labels into ONE tall PNG and describes it in
 * the share text (`labelHeightPx` + a `copies` entry per label), because
 * Android's per-file share limit killed a 22-label run as separate files.
 * This is the arithmetic that takes the sheet back apart.
 *
 * Deliberately pure and Android-free (no Bitmap, no Intent) so it is unit
 * testable on the plain JVM — the same reason TsplImageEncoder is.
 */
object LabelSheet {

    data class Slices(val count: Int, val height: Int)

    /**
     * @param labelHeightPx rows per label from the share metadata, 0 when absent
     * @param copiesCount how many labels the metadata describes, 0 when absent
     */
    fun of(
        bitmapWidth: Int,
        bitmapHeight: Int,
        labelHeightPx: Int,
        copiesCount: Int,
        widthMm: Double,
        heightMm: Double,
        gapMm: Double,
    ): Slices {
        if (bitmapHeight <= 0) return Slices(1, bitmapHeight)

        if (labelHeightPx > 0 && copiesCount > 0) {
            val fits = bitmapHeight / labelHeightPx
            val count = minOf(fits, copiesCount).coerceAtLeast(1)
            // one label means print the image whole: a single-label share
            // is not padded to labelHeightPx, so trusting that figure
            // would crop it
            return Slices(count, if (count > 1) labelHeightPx else bitmapHeight)
        }

        // Defence, not a diagnosis. With no metadata a tall sheet would be
        // sent as ONE label and come out as drifting garbage across several
        // stickers — the shape of the failure Khaled reported when printing
        // every variant at once while one at a time worked. An image
        // exactly as wide as the label and a whole number of labels tall,
        // on a gapped roll, can only be a sheet.
        //
        // All three conditions matter for the memo path (Phase 44) to stay
        // safe: a receipt comes in on continuous paper (gapMm 0) at 576 or
        // 464 dots wide, never the label's own width, so it can never be
        // mistaken for a stack of labels and sliced apart.
        val labelDots = (heightMm * 8).roundToInt()
        val widthDots = (widthMm * 8).roundToInt()
        if (gapMm > 0 && labelDots > 0 && bitmapWidth == widthDots &&
            bitmapHeight > labelDots && bitmapHeight % labelDots == 0
        ) {
            return Slices(bitmapHeight / labelDots, labelDots)
        }

        return Slices(1, bitmapHeight)
    }
}
