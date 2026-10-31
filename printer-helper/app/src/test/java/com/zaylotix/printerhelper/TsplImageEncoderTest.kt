package com.zaylotix.printerhelper

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Test

class TsplImageEncoderTest {

    @Test
    fun `bytesPerRow rounds up to whole bytes`() {
        assertEquals(1, TsplImageEncoder.bytesPerRow(1))
        assertEquals(1, TsplImageEncoder.bytesPerRow(8))
        assertEquals(2, TsplImageEncoder.bytesPerRow(9))
        // the real 38x25mm@203dpi label: 304 dots wide -> exactly 38 bytes/row
        assertEquals(38, TsplImageEncoder.bytesPerRow(304))
    }

    @Test
    fun `packBitmap clears the MSB for the leftmost pixel`() {
        // polarity is inverted (0 = ink): a black pixel at x=0,y=0 in an 8x1
        // image clears bit 7, everything else stays 1 -> byte 0b01111111
        val out = TsplImageEncoder.packBitmap(8, 1) { x, y -> x == 0 && y == 0 }
        assertArrayEquals(byteArrayOf(0x7F), out)
    }

    @Test
    fun `packBitmap clears the LSB for the rightmost pixel of a byte`() {
        val out = TsplImageEncoder.packBitmap(8, 1) { x, y -> x == 7 && y == 0 }
        assertArrayEquals(byteArrayOf(0xFE.toByte()), out)
    }

    @Test
    fun `an all-black row packs to all-zero bytes`() {
        val out = TsplImageEncoder.packBitmap(16, 1) { _, _ -> true }
        assertArrayEquals(byteArrayOf(0x00, 0x00), out)
    }

    @Test
    fun `an all-white image packs to all 0xFF bytes`() {
        val out = TsplImageEncoder.packBitmap(16, 2) { _, _ -> false }
        assertEquals(4, out.size) // 2 bytes/row * 2 rows
        assertArrayEquals(byteArrayOf(0xFF.toByte(), 0xFF.toByte(), 0xFF.toByte(), 0xFF.toByte()), out)
    }

    @Test
    fun `width not a multiple of 8 still packs without crossing into the next row`() {
        // 10px wide -> 2 bytes/row; pixel 9 (last column) must land in byte 1, bit 6
        val out = TsplImageEncoder.packBitmap(10, 1) { x, y -> x == 9 }
        assertEquals(2, out.size)
        assertEquals(0xFF, out[0].toInt() and 0xFF)
        assertEquals(0b10111111.toByte().toInt() and 0xFF, out[1].toInt() and 0xFF)
    }

    @Test
    fun `single-row image total size matches bytesPerRow times height`() {
        val bytes = TsplImageEncoder.packBitmap(304, 200) { x, y -> (x + y) % 7 == 0 }
        assertEquals(38 * 200, bytes.size)
    }

    @Test
    fun `payload contains the exact TSPL command sequence with the bitmap bytes embedded as raw bytes, not re-encoded`() {
        // bytes outside the 0-127 ASCII range (0x80 here) would be silently
        // corrupted by a naive String round-trip through US_ASCII — so this
        // test builds the expected ByteArray directly, byte for byte,
        // rather than comparing decoded strings (which is also why the
        // encoder itself concatenates ByteArrays instead of ever decoding
        // the bitmap portion as text).
        val bitmap = byteArrayOf(0x80.toByte(), 0x01)
        val payload = TsplImageEncoder.buildSingleLabelPayload(
            widthMm = 38.0, heightMm = 25.0, gapMm = 2.0,
            widthPx = 8, heightPx = 1, bitmapBytes = bitmap,
        )

        val expectedHeader = "SIZE 38 mm,25 mm\r\nGAP 2 mm,0 mm\r\nSET TEAR ON\r\nDENSITY 15\r\nSPEED 2\r\nDIRECTION 0\r\nCLS\r\nBITMAP 0,0,1,1,0,".toByteArray(Charsets.US_ASCII)
        val expectedFooter = "\r\nPRINT 1,1\r\n".toByteArray(Charsets.US_ASCII)
        val expected = expectedHeader + bitmap + expectedFooter

        assertArrayEquals(expected, payload)
    }

    @Test
    fun `fractional mm sizes are kept as-is, whole numbers printed without a decimal point`() {
        val payload = TsplImageEncoder.buildSingleLabelPayload(
            widthMm = 38.5, heightMm = 25.0, gapMm = 2.0,
            widthPx = 8, heightPx = 1, bitmapBytes = ByteArray(1),
        )
        val text = String(payload, Charsets.US_ASCII)
        assert(text.startsWith("SIZE 38.5 mm,25 mm\r\n")) { "got: ${text.take(40)}" }
    }
}
