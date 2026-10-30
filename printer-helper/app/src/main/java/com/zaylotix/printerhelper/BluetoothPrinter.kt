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
    }

    private var socket: BluetoothSocket? = null

    @Throws(IOException::class)
    fun connect(device: BluetoothDevice) {
        try {
            BluetoothAdapter.getDefaultAdapter()?.cancelDiscovery()
        } catch (e: SecurityException) {
            // BLUETOOTH_SCAN not granted — harmless here, we never scan, only connect
        }

        // Attempt 1: the proper SDP-based socket. Works on well-behaved
        // Bluetooth stacks, but many cheap thermal/label printers (the
        // XP-365B included) don't answer SDP service queries correctly,
        // which surfaces as an IOException during connect() itself
        // ("read failed, socket might closed or timeout").
        try {
            val s = device.createRfcommSocketToServiceRecord(SPP_UUID)
            s.connect()
            socket = s
            return
        } catch (e: IOException) {
            // fall through to the fallback below
        }

        // Attempt 2: bypass SDP entirely and open RFCOMM channel 1 directly
        // via the hidden createRfcommSocket(int) method. This is the same
        // workaround RawBT/4Barcode and most ESC/POS printer SDKs use for
        // exactly this class of printer.
        val method = device.javaClass.getMethod("createRfcommSocket", Int::class.javaPrimitiveType)
        val s = method.invoke(device, 1) as BluetoothSocket
        s.connect()
        socket = s
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
