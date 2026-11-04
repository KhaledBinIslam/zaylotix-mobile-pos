package com.zaylotix.printerhelper

import android.content.Context

/** The remembered printer's Bluetooth MAC address — a convenience, not durable state; reselect anytime from MainActivity. */
object PrinterPrefs {
    private const val PREFS = "printer_prefs"
    private const val KEY_ADDRESS = "device_address"
    private const val KEY_NAME = "device_name"
    private const val KEY_DENSITY = "print_density" // -1 = unset: send no DENSITY line at all

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

    /** null = never set: the payload says nothing about heat and the printer's own panel setting rules (the only mode the XP-365B tolerates). 0-15 = TSPL DENSITY sent with every label. */
    fun savedDensity(context: Context): Int? {
        val v = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getInt(KEY_DENSITY, -1)
        return if (v in 0..15) v else null
    }

    fun saveDensity(context: Context, density: Int?) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putInt(KEY_DENSITY, density?.coerceIn(0, 15) ?: -1)
            .apply()
    }
}
