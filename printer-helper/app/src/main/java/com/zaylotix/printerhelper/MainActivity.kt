package com.zaylotix.printerhelper

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.ListView
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
    private lateinit var deviceList: ListView
    private lateinit var currentPrinterLabel: TextView
    private lateinit var emptyState: TextView
    private lateinit var statusText: TextView
    private var pairedDevices: List<BluetoothDevice> = emptyList()

    private val permissionLauncher = registerForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) loadPairedDevices() else statusText.text = getString(R.string.permission_needed)
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
            permissionLauncher.launch(Manifest.permission.BLUETOOTH_CONNECT)
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

        val names = pairedDevices.map { "${it.name ?: "(নাম নেই)"}\n${it.address}\n${deviceTypeLabel(it.type)}" }
        deviceList.adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, names)
        deviceList.setOnItemClickListener { _, _, position, _ ->
            val device = pairedDevices[position]
            PrinterPrefs.save(this, device.address, device.name)
            updateCurrentPrinterLabel()
            Toast.makeText(this, "✅ ${device.name ?: device.address} বাছা হয়েছে", Toast.LENGTH_SHORT).show()
        }
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
                val payload = TsplImageEncoder.buildSingleLabelPayload(38.0, 25.0, 2.0, widthPx, heightPx, bitmap)
                printer.write(payload)
                runOnUiThread { statusText.text = getString(R.string.print_success) }
            } catch (e: Exception) {
                val typeNote = "ডিভাইস টাইপ: ${deviceTypeLabel(device.type)}"
                runOnUiThread { statusText.text = getString(R.string.print_failed, e.message ?: e.toString()) + "\n$typeNote" }
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
                runOnUiThread { statusText.text = getString(R.string.print_failed, e.message ?: e.toString()) }
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
