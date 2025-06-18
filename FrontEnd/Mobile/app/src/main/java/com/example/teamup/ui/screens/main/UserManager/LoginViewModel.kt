package com.example.teamup.ui.screens.main.UserManager

import android.util.Log                       // ← NEW
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.teamup.data.domain.usecase.LoginUseCase
import com.example.teamup.data.remote.api.AuthApi
import com.example.teamup.data.remote.api.StoreFcmTokenRequest
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.tasks.await

class LoginViewModel(
    private val loginUseCase: LoginUseCase,
    private val authApi: AuthApi = AuthApi.create()
) : ViewModel() {

    companion object {
        private const val TAG = "LoginViewModel"
    }

    private val _loginState = MutableStateFlow<LoginState>(LoginState.Idle)
    val loginState: StateFlow<LoginState> = _loginState

    /** UI can collect this to show Toast/Snackbar messages */
    private val _toast = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val toast = _toast                    // expose as read-only

    fun login(email: String, password: String) = viewModelScope.launch {
        Log.d(TAG, "🔑 login() called with email=$email")
        _loginState.value = LoginState.Loading

        val result = loginUseCase(email, password)

        result.fold(
            onSuccess = { jwt ->
                Log.d(TAG, "✅ loginUseCase success – got JWT (${jwt.take(12)}…)")
                val bearer = "Bearer $jwt"

                /* 1️⃣  Get device FCM token */
                try {
                    Log.d(TAG, "📡 Fetching FCM token…")
                    val fcmToken = FirebaseMessaging.getInstance().token.await()
                    Log.d(TAG, "📨 FCM token fetched: $fcmToken")

                    /* 2️⃣  Register it with backend */
                    Log.d(TAG, "➡️  Sending token to backend …")
                    val resp = authApi.storeFcmToken(
                        auth = bearer,
                        body = StoreFcmTokenRequest(fcmToken)
                    )
                    Log.d(
                        TAG,
                        "⬅️  storeFcmToken() HTTP ${resp.code()} ${resp.message()}"
                    )
                } catch (e: Exception) {
                    Log.e(TAG, "❌ Could not register FCM token", e)
                    _toast.tryEmit("Could not register push-token: ${e.message}")
                }

                _loginState.value = LoginState.Success(jwt)
            },

            onFailure = { err ->
                Log.e(TAG, "❌ loginUseCase failed", err)
                _loginState.value = LoginState.Error(err.message ?: "Unknown error")
            }
        )
    }
}

/* ─────────── UI state holder ─────────── */
sealed class LoginState {
    object Idle : LoginState()
    object Loading : LoginState()
    data class Success(val token: String) : LoginState()
    data class Error(val message: String) : LoginState()
}
