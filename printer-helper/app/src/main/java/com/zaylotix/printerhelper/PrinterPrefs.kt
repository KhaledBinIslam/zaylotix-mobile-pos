package com.zaylotix.printerhelper

import android.content.Context

/** The remembered printer's Bluetooth MAC address — a convenience, not durable state; reselect anytime from MainActivity. */
object PrinterPrefs {
    private const val PREFS = "printer_prefs"
    private const val KEY_ADDRESS = "device_address"
    private const val KEY_NAME = "device_name"

    fun save(context: Context, address: String, name: String?) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_ADDRESS, address)
            .putString(KEY_NAME, name)
            .apply()
    }

    fun savedAddress(context: Context): String? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_ADDRESS, null)

    fun savedName(context: Context): String? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_NAME, null)
}
