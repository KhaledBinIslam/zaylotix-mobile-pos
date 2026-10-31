package com.zaylotix.printerhelper

import org.junit.Assert.assertEquals
import org.junit.Test

/** 38x25mm at 203dpi (8 dots/mm) = 304x200 dots — the live Baby Park label. */
private const val W = 304
private const val H = 200

class LabelSheetTest {

    private fun of(
        bitmapWidth: Int = W,
        bitmapHeight: Int = H,
        labelHeightPx: Int = 0,
        copiesCount: Int = 0,
        widthMm: Double = 38.0,
        heightMm: Double = 25.0,
        gapMm: Double = 2.0,
    ) = LabelSheet.of(bitmapWidth, bitmapHeight, labelHeightPx, copiesCount, widthMm, heightMm, gapMm)

    @Test
    fun `metadata describing eight stacked labels slices into eight`() {
        val slices = of(bitmapHeight = H * 8, labelHeightPx = H, copiesCount = 8)
        assertEquals(8, slices.count)
        assertEquals(H, slices.height)
    }

    @Test
    fun `a single label prints the whole image, not a labelHeightPx crop`() {
        // a 1-label share is never padded, so its real height is what counts
        val slices = of(bitmapHeight = 198, labelHeightPx = H, copiesCount = 1)
        assertEquals(1, slices.count)
        assertEquals(198, slices.height)
    }

    @Test
    fun `metadata is never trusted past the number of labels it describes`() {
        val slices = of(bitmapHeight = H * 8, labelHeightPx = H, copiesCount = 3)
        assertEquals(3, slices.count)
    }

    @Test
    fun `no metadata still slices a sheet that is a whole number of labels tall`() {
        // the fallback: without this an 8-label sheet printed as ONE label
        // and came out as garbage across several stickers
        val slices = of(bitmapHeight = H * 8)
        assertEquals(8, slices.count)
        assertEquals(H, slices.height)
    }

    @Test
    fun `no metadata and a single label tall stays one label`() {
        assertEquals(1, of(bitmapHeight = H).count)
    }

    @Test
    fun `a continuous-paper receipt is never sliced, however tall it is`() {
        // Phase 44's memo path: 80mm roll, gapMm 0 — one long label
        val slices = of(bitmapWidth = 576, bitmapHeight = H * 9, widthMm = 72.0, heightMm = 225.0, gapMm = 0.0)
        assertEquals(1, slices.count)
        assertEquals(H * 9, slices.height)
    }

    @Test
    fun `a receipt whose metadata went missing is still never sliced`() {
        // worst case for the fallback: EXTRA_TEXT absent, so the defaults
        // (38x25, gap 2) apply to a 576-wide receipt that happens to be an
        // exact multiple of 200 tall. The width check is what saves it.
        val slices = of(bitmapWidth = 576, bitmapHeight = 1800)
        assertEquals(1, slices.count)
        assertEquals(1800, slices.height)
    }

    @Test
    fun `an image not a whole number of labels tall is left alone`() {
        assertEquals(1, of(bitmapHeight = H * 3 + 17).count)
    }

    @Test
    fun `an empty image never reports zero slices`() {
        assertEquals(1, of(bitmapHeight = 0).count)
    }
}
