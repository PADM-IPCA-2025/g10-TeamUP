// File: app/src/main/java/com/example/teamup/ui/screens/HomeScreen.kt
package com.example.teamup.ui.screens

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material3.Button
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.teamup.data.domain.model.ActivityItem
import com.example.teamup.data.domain.repository.ActivityRepository
import com.example.teamup.data.remote.Repository.ActivityRepositoryImpl
import com.example.teamup.data.remote.api.ActivityApi
import com.example.teamup.ui.components.ActivityCard
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.accompanist.permissions.ExperimentalPermissionsApi
import com.google.accompanist.permissions.PermissionStatus
import com.google.accompanist.permissions.rememberPermissionState
import com.google.android.gms.maps.model.LatLngBounds

// ▶ CORRECT imports for Compose‐Maps:
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.MapEffect
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapUiSettings
import com.google.maps.android.compose.MapsComposeExperimentalApi
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.rememberCameraPositionState
import com.google.maps.android.compose.rememberMarkerState

@OptIn(ExperimentalPermissionsApi::class)
@Composable
fun HomeScreen(
    token: String,
    onActivityClick: (ActivityItem) -> Unit
) {
    /* 1) ViewModel with injected repository */
    val vm: HomeViewModel = viewModel(
        factory = object : ViewModelProvider.Factory {
            @Suppress("UNCHECKED_CAST")
            override fun <T : ViewModel> create(modelClass: Class<T>): T {
                val repo: ActivityRepository = ActivityRepositoryImpl(ActivityApi.create())
                return HomeViewModel(repo) as T
            }
        }
    )


    /* 2) UI-state from the VM */
    val allActivities     by vm.activities.collectAsState()
    val visibleActivities by vm.visibleActivities.collectAsState()
    val hasMore           by vm.hasMore.collectAsState()
    val error             by vm.error.collectAsState()
    val center            by vm.center.collectAsState()


    /* 3) Context & fine-location permission */
    val ctx = LocalContext.current
    val locPerm = rememberPermissionState(Manifest.permission.ACCESS_FINE_LOCATION)
    val locationGranted = locPerm.status is PermissionStatus.Granted

    /* ─── SHOW THE DIALOG WHEN NEEDED ─── */
    LaunchedEffect(locPerm.status) {
        if (locPerm.status is PermissionStatus.Denied) {
            // This launches the system prompt exactly once per “Denied” state
            locPerm.launchPermissionRequest()
        }
    }

    LaunchedEffect(token) {
        vm.loadActivities(token)
    }
    // 2) Only react to permission changes for centering the map
    LaunchedEffect(locPerm.status) {
        if (locPerm.status is PermissionStatus.Granted) {
            vm.fetchAndCenterOnGps(ctx)
        } else {
            vm.loadFallbackCenter(token, ctx)
        }
    }


    /* 5) UI layout */
    Column(modifier = Modifier.fillMaxSize()) {

        /* Map with “My location” FAB layered on top */
        Box(
            modifier = Modifier
                .height(300.dp)
                .fillMaxWidth()
        ) {
            MapView(
                modifier   = Modifier.matchParentSize(),
                activities = allActivities,
                center     = center,
                locationGranted = locationGranted,
                onBoundsChanged  = vm::updateMapBounds


            )


        }

        error?.let {
            Text(
                text = "Failed to load activities: $it",
                color = Color.Red,
                modifier = Modifier.padding(16.dp)
            )
        }

        ActivitiesList(
            activities      = visibleActivities,
            hasMore         = hasMore,
            onLoadMore      = { vm.loadMore() },
            onActivityClick = onActivityClick
        )
    }
}
@OptIn(MapsComposeExperimentalApi::class)
@Composable
private fun MapView(
    modifier: Modifier,
    activities: List<ActivityItem>,
    center: LatLng,
    locationGranted: Boolean,
    onBoundsChanged: (LatLngBounds) -> Unit
) {
    val cameraState = rememberCameraPositionState()

    /**
     * Whenever the VM publishes a new centre:
     *   1. Instantly move() so the map jumps even if it's still binding.
     *   2. Immediately start a smooth animate() so the jump is hardly visible.
     */
    LaunchedEffect(center) {
        val update = CameraUpdateFactory.newLatLngZoom(center, 14f)
        cameraState.move(update)                    // always succeeds
        cameraState.animate(update)                 // smooth once bound
    }

    Box(modifier = modifier.background(Color.White, MaterialTheme.shapes.medium)) {
        GoogleMap(
            modifier = Modifier.matchParentSize(),
            cameraPositionState = cameraState,

            properties = MapProperties(isMyLocationEnabled = locationGranted),
            uiSettings = MapUiSettings(myLocationButtonEnabled = locationGranted),
        ) {
            activities.forEach { act ->
                Marker(
                    state = rememberMarkerState(position = LatLng(act.latitude, act.longitude)),
                    title = act.title
                )
            }
            // 2) Inside the GoogleMap content lambda, attach MapEffect
            MapEffect(cameraState) { googleMap ->
                // 3) Register the idle-listener on the raw GoogleMap
                googleMap.setOnCameraIdleListener {
                    // when idle, compute the new visible bounds and pass them up
                    val bounds = googleMap.projection.visibleRegion.latLngBounds
                    onBoundsChanged(bounds)
                }
            }
        }

    }
}

@Composable
private fun ActivitiesList(
    activities: List<ActivityItem>,
    hasMore: Boolean,
    onLoadMore: () -> Unit,
    onActivityClick: (ActivityItem) -> Unit
) {
    LazyColumn(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        /* Header */
        item {
            Text(
                text = "Available Activities nearby:",
                color = Color(0xFF023499),
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 12.dp)
            )
        }

        if (activities.isEmpty()) {
            /* Placeholder when nothing to show */
            item {
                Text(
                    text = "No activities near you 😞",
                    color = Color(0xFF023499),
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(vertical = 8.dp)
                )
            }
        } else {
            /* Paged subset */
            items(activities, key = { it.id }) { act ->
                ActivityCard(
                    activity      = act,
                    bgColor       = if (act.isCreator) Color(0xFFE3F2FD) else Color(0xFFF5F5F5),
                    labelCreator  = if (act.isCreator) "You are the creator" else null,
                    onClick       = { onActivityClick(act) }
                )
            }

            /* “Load more” button */
            if (hasMore) {
                item {
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(vertical = 8.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        Button(
                            onClick = onLoadMore,
                            modifier = Modifier
                                .fillMaxWidth(0.5f)
                                .padding(8.dp)
                        ) {
                            Text("Load more")
                        }
                    }
                }
            }
        }
    }
}
