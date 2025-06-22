// File: app/src/main/java/com/example/teamup/presentation/profile/EditProfileViewModel.kt
package com.example.teamup.presentation.profile

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.teamup.domain.model.Sport
import com.example.teamup.domain.model.UpdateUserRequestDomain
import com.example.teamup.domain.repository.ActivityRepository
import com.example.teamup.domain.repository.UserRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import android.net.Uri
import android.content.ContentResolver
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody

data class EditProfileUiState(
    val saving: Boolean = false,
    val avatarBusy: Boolean = false,
    val error: String? = null,
    val done: Boolean = false
)

class EditProfileViewModel(
    private val userRepo: UserRepository,
    private val activityRepo: ActivityRepository
) : ViewModel() {

    private val _ui = MutableStateFlow(EditProfileUiState())
    val ui: StateFlow<EditProfileUiState> = _ui

    private val _sports = MutableStateFlow<List<Sport>>(emptyList())
    val sports: StateFlow<List<Sport>> = _sports

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error

    fun save(
        bearer: String,
        username: String,
        location: String,
        lat: Double?,
        lng: Double?,
        sportIds: List<Int>
    ) {
        viewModelScope.launch {
            _ui.value = EditProfileUiState(saving = true)
            try {
                userRepo.updateMe(
                    bearer,
                    UpdateUserRequestDomain(
                        name = username.trim().takeIf { it.isNotBlank() },
                        email = null,
                        location = location.trim().takeIf { it.isNotBlank() },
                        latitude = lat,
                        longitude = lng,
                        sports = sportIds.ifEmpty { null }
                    )
                )
                _ui.value = EditProfileUiState(done = true)
            } catch (e: Exception) {
                _ui.value = EditProfileUiState(error = e.message)
            }
        }
    }

    fun deleteAccount(token: String) {
        viewModelScope.launch {
            _ui.value = EditProfileUiState(saving = true)
            try {
                userRepo.deleteMe(token)
                _ui.value = EditProfileUiState(done = true)
            } catch (e: Exception) {
                _ui.value = EditProfileUiState(error = e.message)
            }
        }
    }

    fun loadSports(bearer: String) = viewModelScope.launch {
        _error.value = null
        try {
            _sports.value = activityRepo.getSports(bearer)
        } catch (e: Exception) {
            _error.value = e.message
        }
    }

    fun uploadAvatar(bearer: String, uri: Uri, resolver: ContentResolver) {
        viewModelScope.launch {
            _ui.value = _ui.value.copy(avatarBusy = true, error = null)
            try {
                val stream = resolver.openInputStream(uri)!!
                val bytes = stream.readBytes()
                val req = bytes.toRequestBody("image/*".toMediaType())
                val part = MultipartBody.Part.createFormData(
                    name = "avatar",
                    filename = "avatar_${System.currentTimeMillis()}.jpg",
                    body = req
                )

                userRepo.uploadAvatar(bearer, part)
                _ui.value = _ui.value.copy(avatarBusy = false)
            } catch (e: Exception) {
                _ui.value = _ui.value.copy(
                    avatarBusy = false,
                    error = e.message
                )
            }
        }
    }
}
