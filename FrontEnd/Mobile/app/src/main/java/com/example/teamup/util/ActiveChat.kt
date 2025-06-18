package com.example.teamup.util

/** Keeps track of the chat screen that is currently visible (if any). */
object ActiveChat {
    /** The eventId of the chat detail screen in the foreground, or null. */
    @Volatile var currentEventId: Int? = null
}