package com.example.teamup.ui.screens

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.location.Geocoder
import android.os.Looper
import com.google.android.gms.location.LocationRequest
import android.util.Base64
import androidx.annotation.RequiresPermission
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.teamup.data.domain.model.ActivityItem
import com.example.teamup.data.domain.repository.ActivityRepository
import com.example.teamup.data.remote.api.AuthApi
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.flow.*
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.io.IOException
import java.util.Locale
import kotlin.math.*

class HomeViewModel(
    private val repo: ActivityRepository
) : ViewModel() {

    /* ▼ NEW: last visible map area */
    private val _mapBounds = MutableStateFlow<LatLngBounds?>(null)
    fun updateMapBounds(bounds: LatLngBounds) {          // call from the map
        _mapBounds.value = bounds
    }

    /* ────── raw data from server ────── */
    private val _allActivities = MutableStateFlow<List<ActivityItem>>(emptyList())
    private val _error         = MutableStateFlow<String?>(null)

    /* ────── map centre ────── */
    private val defaultCenter  = LatLng(41.5381, -8.6151)
    private val _center        = MutableStateFlow(defaultCenter)

    /* ────── pagination state (local) ────── */
    private val pageSize            = 10
    private val currentPageLocal    = MutableStateFlow(1)
    private val _visibleActivities  = MutableStateFlow<List<ActivityItem>>(emptyList())
    private val _hasMoreLocal       = MutableStateFlow(false)

    /** Lista filtrada (não concluído + <=25 km) – usada no mapa */
    private val _filteredActivities = MutableStateFlow<List<ActivityItem>>(emptyList())

    /* ────── public flows ────── */
    val error: StateFlow<String?>                     = _error
    val center: StateFlow<LatLng>                     = _center
    val visibleActivities: StateFlow<List<ActivityItem>> = _visibleActivities
    val hasMore: StateFlow<Boolean>                   = _hasMoreLocal
    val activities: StateFlow<List<ActivityItem>>     = _filteredActivities   // para o mapa


    init {
        // Whenever server list, map centre **or bounds** change → rebuild paging
        viewModelScope.launch {
            combine(_allActivities, _center, _mapBounds) { list, _, bounds ->
                list.filter { item ->
                    // ① hide concluded, ② keep only items inside the map
                    !item.status.equals("concluded", true) &&
                            (bounds == null ||                       // first launch
                                    bounds.contains(LatLng(item.latitude, item.longitude)))
                }
            }.collect { filtered ->
                _filteredActivities.value = filtered       // list for the map pins
                currentPageLocal.value = 1                 // restart local paging
                _visibleActivities.value = filtered.take(pageSize)
                _hasMoreLocal.value = filtered.size > pageSize
            }
        }
    }


    /** Busca **todas** as páginas do backend, acumulando resultados */
    fun loadActivities(rawToken: String) = viewModelScope.launch {
        try {
            val userId   = getUserIdFromToken(rawToken) ?: -1
            val token    = bearer(rawToken)
            val allItems = mutableListOf<ActivityItem>()
            var page     = 1
            do {
                // busca página 'page' com, por exemplo, perPage = 50
                val pageItems = repo.getAllEvents(token, page = page, perPage = 50)
                allItems += pageItems
                page++
            } while (repo.hasMore)
            // anota “isCreator” e dispara para a UI
            _allActivities.value = allItems.map { it.copy(isCreator = (it.creatorId == userId)) }
            _error.value         = null
        } catch (e: Exception) {
            _error.value = e.localizedMessage
        }
    }

    /** Fallback para encontrar centro do mapa */
    fun loadFallbackCenter(rawToken: String, ctx: Context) = viewModelScope.launch {
        try {
            val token  = bearer(rawToken)
            val uid    = getUserIdFromToken(token) ?: return@launch
            val user   = AuthApi.create().getUser(uid, token)

            when {
                user.latitude != null && user.longitude != null ->
                    _center.value = LatLng(user.latitude, user.longitude)

                !user.location.isNullOrBlank() ->
                    geocode(user.location!!, ctx)?.let { _center.value = it }
            }
        } catch (_: Exception) { /* mantém centro corrente */ }
    }

    /** Avança uma página local (10 itens) sem chamar backend */
    fun loadMore() {
        val filtered = _filteredActivities.value
        val nextPage = currentPageLocal.value + 1
        val toIndex  = (nextPage * pageSize).coerceAtMost(filtered.size)

        _visibleActivities.value = filtered.take(toIndex)
        currentPageLocal.value = nextPage
        _hasMoreLocal.value = filtered.size > toIndex
    }

    /* ────── helpers ────── */

    private fun bearer(tok: String) =
        if (tok.startsWith("Bearer")) tok.trim() else "Bearer ${tok.trim()}"

    private fun getUserIdFromToken(tokenWithBearer: String): Int? = try {
        val payload = tokenWithBearer.removePrefix("Bearer ").split(".")[1]
        val json = JSONObject(String(Base64.decode(payload, Base64.URL_SAFE or Base64.NO_WRAP)))
        json.getInt("sub")
    } catch (_: Exception) { null }

    private fun geocode(city: String, ctx: Context): LatLng? = try {
        Geocoder(ctx, Locale.getDefault()).getFromLocationName(city, 1)
            ?.firstOrNull()?.let { LatLng(it.latitude, it.longitude) }
    } catch (_: IOException) { null }



    /* Force-set centre even if numerically the same */
    /* Force-emit even if coordinates are identical */
    private fun pushCenterAlways(lat: Double, lon: Double) {
        val newCenter = LatLng(lat, lon)

        // If nothing changed, inject a tiny “jitter” first
        if (newCenter == _center.value) {
            _center.value = LatLng(lat, lon + 0.005)      // ≈ 500 m east
        }

        _center.value = newCenter                          // then real point
    }
    @SuppressLint("MissingPermission")
    fun fetchAndCenterOnGps(ctx: Context) {
        val fused = LocationServices.getFusedLocationProviderClient(ctx)

        val tokenSrc = CancellationTokenSource()
        fused.getCurrentLocation(
            Priority.PRIORITY_HIGH_ACCURACY,           // ← was BALANCED
            tokenSrc.token
        ).addOnSuccessListener { loc ->
            loc?.let { pushCenterAlways(it.latitude, it.longitude) }
                ?: requestSingleUpdate(fused)          // fall-back
        }.addOnFailureListener { e ->
            _error.value = "Could not get location: ${e.localizedMessage}"
        }
    }


    /* one accurate fix, then stop */
    @RequiresPermission(allOf = [Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION])
    private fun requestSingleUpdate(fused: FusedLocationProviderClient) {
        val req = LocationRequest.Builder(
            Priority.PRIORITY_HIGH_ACCURACY,
            2_000L                                     // 2 s – must be > 0
        )
            .setMaxUpdates(1)
            .build()

        fused.requestLocationUpdates(req, object : LocationCallback() {
            override fun onLocationResult(res: LocationResult) {
                res.lastLocation?.let {
                    pushCenterAlways(it.latitude, it.longitude)
                }
                fused.removeLocationUpdates(this)
            }
        }, Looper.getMainLooper())
    }


}