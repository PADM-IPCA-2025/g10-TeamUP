package com.example.teamup.data.remote.api

import com.example.teamup.data.remote.BaseUrlProvider
import com.example.teamup.data.remote.model.*
import okhttp3.OkHttpClient                     // ← NEW
import okhttp3.logging.HttpLoggingInterceptor  // ← NEW
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.*

data class StoreFcmTokenRequest(val fcm_token: String)

interface AuthApi {

    @POST("/api/auth/register")
    suspend fun register(@Body body: RegisterRequestDto): Response<GenericMessageResponseDto>

    @POST("/api/auth/login")
    suspend fun login(@Body body: LoginRequestDto): LoginResponseDto

    @GET("/api/auth/me")
    suspend fun getCurrentUser(): UserDto

    @POST("/api/store-fcm-token")
    suspend fun storeFcmToken(
        @Header("Authorization") auth: String,
        @Body body: StoreFcmTokenRequest
    ): Response<Unit>

    /** GET /api/users/{id} – public profile (minimal fields). */
    @GET("/api/users/{id}")
    suspend fun getUser(
        @Path("id") id: Int,
        @Header("Authorization") auth: String
    ): PublicUserDto

    /** PUT /api/auth/update – partial updates */
    @PUT("/api/auth/update")
    suspend fun updateMe(
        @Header("Authorization") auth: String,
        @Body body: UpdateUserRequest
    ): UserDto

    /** DELETE /api/auth/delete – deletes the logged-in user */
    @DELETE("/api/auth/delete")
    suspend fun deleteMe(
        @Header("Authorization") auth: String
    )

    // ─── NEW “send reset link” endpoint ────────────────────────────────
    @POST("/api/password/email")
    suspend fun sendResetLink(@Body body: ForgotPasswordRequestDto): Response<GenericMessageResponseDto>

    @POST("/api/auth/change-password")
    suspend fun changePassword(
        @Header("Authorization") auth: String,
        @Body body: ChangePasswordRequestDto
    ): Response<GenericMessageResponseDto>

    @POST("/api/auth/change-email")
    suspend fun changeEmail(
        @Header("Authorization") auth: String,
        @Body body: ChangeEmailRequestDto
    ): Response<GenericMessageResponseDto>

    // ------------------------------------------------------------------

    companion object {
        fun create(): AuthApi {
            // ★ OkHttp network logger
            val logging = HttpLoggingInterceptor().apply {
                level = HttpLoggingInterceptor.Level.BODY    // headers + JSON + body
            }

            val client = OkHttpClient.Builder()
                .addInterceptor(logging)
                .build()

            return Retrofit.Builder()
                .baseUrl(BaseUrlProvider.getBaseUrl())
                .client(client)                              // ← ADD
                .addConverterFactory(GsonConverterFactory.create())
                .build()
                .create(AuthApi::class.java)
        }
    }
}
