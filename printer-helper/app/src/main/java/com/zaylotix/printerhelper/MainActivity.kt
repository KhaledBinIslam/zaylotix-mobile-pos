package com.zaylotix.printerhelper

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.LayoutInflater
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

/**
 * The only screen a shop owner ever opens directly — pick which paired
 * Bluetooth printer this phone should print to (remembered for next time),
 * plus a one-tap test print to confirm it actually works before relying on
 * it from the main Zaylotix app's share button.
 */
class MainActivity : AppCompatActivity() {

    private val bluetoothAdapter: BluetoothAdapter? by lazy { BluetoothAdapter.getDefaultAdapter() }
    private lateinit var deviceList: LinearLayout
    private lateinit var currentPrinterLabel: TextView
    private lateinit var emptyState: TextView
    private lateinit var statusText: TextView
    private var pairedDevices: List<BluetoothDevice> = emptyList()

    /**
     * CONNECT is what the whole app rests on; SCAN is only so
     * cancelDiscovery() can run before a connect (see the manifest). A
     * refused SCAN is therefore not fatal — the list and the printing both
     * go on working — so only CONNECT decides what this reports.
     */
    private val permissionLauncher = registerForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.RequestMultiplePermissions()
    ) { granted ->
        if (granted[Manifest.permission.BLUETOOTH_CONNECT] == true) loadPairedDevices()
        else statusText.text = getString(R.string.permission_needed)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        deviceList = findViewById(R.id.deviceList)
        currentPrinterLabel = findViewById(R.id.currentPrinterLabel)
        emptyState = findViewById(R.id.emptyState)
        statusText = findViewById(R.id.statusText)

        findViewById<Button>(R.id.openBluetoothSettingsBtn).setOnClickListener {
            startActivity(Intent(Settings.ACTION_BLUETOOTH_SETTINGS))
        }
        findViewById<Button>(R.id.testPrintBtn).setOnClickListener { runTestPrint() }
        findViewById<Button>(R.id.calibrateBtn).setOnClickListener { runCalibration() }

        // Print darkness, 4Barcode-style: − below 0 falls off the scale
        // back to "the printer's own setting", which sends no DENSITY
        // line at all — the only state every firmware tolerates, and the
        // state every shop starts in.
        findViewById<Button>(R.id.densityDownBtn).setOnClickListener {
            val current = PrinterPrefs.savedDensity(this)
            PrinterPrefs.saveDensity(this, when {
                current == null -> null // already at default — stay there
                current <= 0 -> null    // stepping below 0 returns to default
                else -> current - 1
            })
            updateDensityLabel()
        }
        findViewById<Button>(R.id.densityUpBtn).setOnClickListener {
            val current = PrinterPrefs.savedDensity(this)
            // first + lands on 8, the middle of TSPL's 0-15 — starting a
            // "make it darker" journey at 0 (the lightest) would read as
            // the button doing the opposite of what it says
            PrinterPrefs.saveDensity(this, if (current == null) 8 else (current + 1).coerceAtMost(15))
            updateDensityLabel()
        }
        updateDensityLabel()

        // on screen so a support question never has to guess which build
        // is installed — see the layout comment on versionText. Read from
        // the package manager rather than BuildConfig, which this Gradle
        // setup does not generate.
        findViewById<android.widget.TextView>(R.id.versionText).text = try {
            val info = packageManager.getPackageInfo(packageName, 0)
            val code = if (Build.VERSION.SDK_INT >= 28) info.longVersionCode else {
                @Suppress("DEPRECATION") info.versionCode.toLong()
            }
            getString(R.string.version_line, info.versionName ?: "?", code)
        } catch (e: Exception) {
            ""
        }

        ensurePermissionThenLoad()
    }

    override fun onResume() {
        super.onResume()
        updateCurrentPrinterLabel()
        if (hasBluetoothPermission()) loadPairedDevices()
    }

    private fun hasBluetoothPermission(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true
        return ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
    }

    private fun ensurePermissionThenLoad() {
        if (hasBluetoothPermission()) {
            loadPairedDevices()
        } else {
            permissionLauncher.launch(arrayOf(Manifest.permission.BLUETOOTH_CONNECT, Manifest.permission.BLUETOOTH_SCAN))
        }
    }

    @Suppress("MissingPermission") // hasBluetoothPermission() already checked before every call site
    private fun loadPairedDevices() {
        val adapter = bluetoothAdapter
        if (adapter == null) {
            statusText.text = "এই ফোনে Bluetooth নেই।"
            return
        }
        if (!hasBluetoothPermission()) return

        pairedDevices = adapter.bondedDevices?.toList() ?: emptyList()
        emptyState.visibility = if (pairedDevices.isEmpty()) android.view.View.VISIBLE else android.view.View.GONE

        // Rows built by hand rather than through an adapter: see
        // activity_main.xml's own comment on why the ListView had to go.
        // Rebuilt from scratch on every call because onResume() refreshes
        // the list after a trip to Bluetooth settings, where the owner may
        // have paired or forgotten a device.
        val selected = PrinterPrefs.savedAddress(this)
        deviceList.removeAllViews()
        val inflater = LayoutInflater.from(this)
        for (device in pairedDevices) {
            val row = inflater.inflate(R.layout.device_row, deviceList, false)
            row.findViewById<TextView>(R.id.rowName).text = device.name ?: getString(R.string.device_no_name)
            row.findViewById<TextView>(R.id.rowAddress).text = device.address
            row.findViewById<TextView>(R.id.rowType).text = deviceTypeLabel(device.type)
            // which one is in use has to be readable at a glance — the
            // line at the top of the screen was the only answer before,
            // and it is scrolled away by the time the list is reached
            row.findViewById<TextView>(R.id.rowTick).text =
                if (device.address == selected) getString(R.string.selected_tick) else ""
            row.setBackgroundColor(if (device.address == selected) 0xFFF0FFF4.toInt() else 0xFFFFFFFF.toInt())
            row.setOnClickListener {
                PrinterPrefs.save(this, device.address, device.name)
                updateCurrentPrinterLabel()
                loadPairedDevices() // move the tick to the row just tapped
                statusText.text = ""
                Toast.makeText(this, getString(R.string.device_picked, device.name ?: device.address), Toast.LENGTH_SHORT).show()
            }
            deviceList.addView(row)
        }
    }

    private fun updateDensityLabel() {
        val density = PrinterPrefs.savedDensity(this)
        findViewById<TextView>(R.id.densityValue).text =
            if (density == null) getString(R.string.density_default_label)
            else getString(R.string.density_level_label, density)
    }

    private fun updateCurrentPrinterLabel() {
        val name = PrinterPrefs.savedName(this)
        val address = PrinterPrefs.savedAddress(this)
        currentPrinterLabel.text = getString(R.string.current_printer) + ": " +
            (if (address != null) "${name ?: ""} ($address)" else getString(R.string.none_selected))
    }

    @Suppress("MissingPermission")
    private fun runTestPrint() {
        val address = PrinterPrefs.savedAddress(this)
        if (address == null) {
            Toast.makeText(this, R.string.no_printer_selected, Toast.LENGTH_LONG).show()
            return
        }
        if (!hasBluetoothPermission()) {
            Toast.makeText(this, R.string.permission_needed, Toast.LENGTH_LONG).show()
            return
        }
        val device = bluetoothAdapter?.getRemoteDevice(address)
        if (device == null) {
            Toast.makeText(this, "ডিভাইস পাওয়া যায়নি।", Toast.LENGTH_LONG).show()
            return
        }

        statusText.text = getString(R.string.printing_in_progress)
        Thread {
            val printer = BluetoothPrinter()
            try {
                printer.connect(device)
                // border + diagonal "X" test pattern — proves the bitmap's
                // orientation/size lines up with the physical label without
                // needing any text rendering here
                val widthPx = 304
                val heightPx = 200
                val bitmap = TsplImageEncoder.packBitmap(widthPx, heightPx) { x, y ->
                    x < 4 || x >= widthPx - 4 || y < 4 || y >= heightPx - 4 ||
                        kotlin.math.abs(x.toDouble() / widthPx - y.toDouble() / heightPx) < 0.01 ||
                        kotlin.math.abs(x.toDouble() / widthPx - (1 - y.toDouble() / heightPx)) < 0.01
                }
                // the saved darkness rides on the test print too, so "set,
                // test, look" is one loop on this screen
                val payload = TsplImageEncoder.buildSingleLabelPayload(38.0, 25.0, 2.0, widthPx, heightPx, bitmap, density = PrinterPrefs.savedDensity(this))
                printer.write(payload)
                runOnUiThread { statusText.text = getString(R.string.print_success) }
            } catch (e: Exception) {
                val detail = (e.message ?: e.toString()) + " · " + deviceTypeLabel(device.type)
                runOnUiThread { statusText.text = getString(R.string.print_failed_help, detail) }
            } finally {
                printer.disconnect()
            }
        }.start()
    }

    @Suppress("MissingPermission")
    private fun runCalibration() {
        val address = PrinterPrefs.savedAddress(this)
        if (address == null) {
            Toast.makeText(this, R.string.no_printer_selected, Toast.LENGTH_LONG).show()
            return
        }
        if (!hasBluetoothPermission()) {
            Toast.makeText(this, R.string.permission_needed, Toast.LENGTH_LONG).show()
            return
        }
        val device = bluetoothAdapter?.getRemoteDevice(address)
        if (device == null) {
            Toast.makeText(this, "ডিভাইস পাওয়া যায়নি।", Toast.LENGTH_LONG).show()
            return
        }

        statusText.text = getString(R.string.calibrating)
        Thread {
            val printer = BluetoothPrinter()
            try {
                printer.connect(device)
                printer.write(TsplImageEncoder.buildCalibrationPayload(38.0, 25.0, 2.0))
                // GAPDETECT feeds several labels through the sensor on its
                // own — give it time to finish before the next connect().
                Thread.sleep(4000)
                runOnUiThread { statusText.text = getString(R.string.calibrate_success) }
            } catch (e: Exception) {
                runOnUiThread { statusText.text = getString(R.string.print_failed_help, e.message ?: e.toString()) }
            } finally {
                printer.disconnect()
            }
        }.start()
    }

    private fun deviceTypeLabel(type: Int): String = when (type) {
        BluetoothDevice.DEVICE_TYPE_CLASSIC -> "CLASSIC (SPP কাজ করার কথা)"
        BluetoothDevice.DEVICE_TYPE_LE -> "LE / BLE-only (classic RFCOMM কাজ করবে না!)"
        BluetoothDevice.DEVICE_TYPE_DUAL -> "DUAL (classic + LE দুটোই আছে)"
        else -> "UNKNOWN"
    }
}
