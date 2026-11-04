package com.zaylotix.printerhelper

import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import java.io.IOException
import java.util.UUID

/**
 * Thin wrapper over a classic-Bluetooth (SPP) connection to a paired
 * printer. The SPP UUID below is the universal Serial Port Profile id —
 * every classic-Bluetooth thermal/label printer (including the XP-365B)
 * uses it; this is exactly how RawBT/4Barcode talk to the same printer,
 * just done ourselves instead of through a third-party app.
 *
 * Kept deliberately dumb: one connection at a time, synchronous blocking
 * I/O — callers (ShareReceiverActivity, MainActivity's test print) run
 * this off the main thread themselves.
 */
class BluetoothPrinter {
    companion object {
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

        /**
         * This printer refuses the first connect often enough that one
         * attempt is not evidence of anything — it had been asleep, or the
         * stack was still tearing down the previous session. Three rounds
         * with a pause between them is what makes a working printer answer
         * reliably; a printer that is genuinely off still fails all three
         * and reaches the owner as the same error, only later.
         */
        private const val ROUNDS = 3
        private const val PAUSE_MS = 700L

        /**
         * RFCOMM channel 1 is where SPP lives on every unit seen so far,
         * but a firmware that has moved it answers on 2 — cheap to try
         * once each round rather than leaving the owner with a dead app.
         */
        private val CHANNELS = intArrayOf(1, 2)

        /** Secure first, then the unauthenticated twin — a printer holding no link key only answers the second. */
        private val RAW_METHODS = arrayOf("createRfcommSocket", "createInsecureRfcommSocket")
    }

    private var socket: BluetoothSocket? = null

    @Throws(IOException::class)
    fun connect(device: BluetoothDevice) {
        try {
            BluetoothAdapter.getDefaultAdapter()?.cancelDiscovery()
        } catch (e: SecurityException) {
            // BLUETOOTH_SCAN not granted — harmless here, we never scan, only connect
        }

        var last: IOException? = null
        for (round in 0 until ROUNDS) {
            if (round > 0) {
                try {
                    Thread.sleep(PAUSE_MS)
                } catch (e: InterruptedException) {
                    Thread.currentThread().interrupt()
                    throw IOException("সংযোগ বাতিল হয়েছে।")
                }
            }

            // Attempt 1: the proper SDP-based socket. Works on well-behaved
            // Bluetooth stacks, but many cheap thermal/label printers (the
            // XP-365B included) don't answer SDP service queries correctly,
            // which surfaces as an IOException during connect() itself
            // ("read failed, socket might closed or timeout").
            try {
                val s = device.createRfcommSocketToServiceRecord(SPP_UUID)
                if (openOrClose(s)) return
            } catch (e: IOException) {
                last = e
            }

            // Attempt 2: the same SDP lookup without the encryption and
            // authentication the secure variant insists on. Printers that
            // hold no link key — the S29 among them — refuse the secure
            // socket outright while answering this one, so skipping it
            // left a working printer looking dead.
            try {
                val s = device.createInsecureRfcommSocketToServiceRecord(SPP_UUID)
                if (openOrClose(s)) return
            } catch (e: IOException) {
                last = e
            }

            // Attempt 3: bypass SDP entirely and open an RFCOMM channel
            // directly via the hidden createRfcommSocket(int) method, then
            // its insecure twin. This is the same workaround RawBT/
            // 4Barcode and most ESC/POS printer SDKs use for exactly this
            // class of printer.
            for (channel in CHANNELS) {
                for (name in RAW_METHODS) {
                    try {
                        val method = device.javaClass.getMethod(name, Int::class.javaPrimitiveType)
                        val s = method.invoke(device, channel) as BluetoothSocket
                        if (openOrClose(s)) return
                    } catch (e: IOException) {
                        last = e
                    } catch (e: Exception) {
                        // reflection refused on this Android build — nothing
                        // more this path can do, let the SDP error stand
                        last = last ?: IOException(e.message ?: e.toString())
                    }
                }
            }
        }

        throw last ?: IOException("প্রিন্টারে সংযোগ করা যায়নি।")
    }

    /**
     * A socket whose connect() failed must be closed before the next
     * attempt. Leaking it leaves the channel half-open inside the Android
     * stack, so the FALLBACK attempt then fails too — which is how one
     * refused connect turned into "read failed, socket might closed or
     * timeout, read ret: -1" on every path, every time, until Bluetooth
     * itself was toggled.
     */
    private fun openOrClose(candidate: BluetoothSocket): Boolean {
        return try {
            candidate.connect()
            socket = candidate
            true
        } catch (e: IOException) {
            try {
                candidate.close()
            } catch (ignored: IOException) {
                // nothing to clean up — the socket never opened
            }
            throw e
        }
    }

    @Throws(IOException::class)
    fun write(bytes: ByteArray) {
        val stream = socket?.outputStream ?: throw IOException("প্রিন্টারের সাথে সংযোগ নেই।")
        stream.write(bytes)
        stream.flush()
    }

    fun disconnect() {
        try {
            socket?.close()
        } catch (e: IOException) {
            // already closed / never opened — fine to ignore on cleanup
        }
        socket = null
    }
}
