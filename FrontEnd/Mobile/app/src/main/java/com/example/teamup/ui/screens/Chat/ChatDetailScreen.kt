package com.example.teamup.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Send
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import com.example.teamup.model.Message
import com.example.teamup.network.ChatApi
import com.example.teamup.network.ChatWebSocket
import com.example.teamup.util.ActiveChat

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatDetailScreen(
    chatTitle: String,
    eventId:   Int,
    token:     String,
    myUserId:  Int,
    onBack:    () -> Unit
) {

    DisposableEffect(Unit) {
        ActiveChat.currentEventId = eventId          // ← NEW
        onDispose { ActiveChat.currentEventId = null }  // ← NEW
    }

    /* ── State ──────────────────────────────────────────────────────────── */
    val messages       = remember { mutableStateListOf<Message>() }
    var input          by remember { mutableStateOf("") }
    var loadingHistory by remember { mutableStateOf(true) }
    val scope          = rememberCoroutineScope()

    /* ── WebSocket connection ───────────────────────────────────────────── */
    DisposableEffect(Unit) {
        val ws = ChatWebSocket(token, eventId, myUserId).also { it.connect() }

        // Collect incoming WS messages
        val job = scope.launch {
            ws.incoming.collectLatest { msg ->
                messages.add(0, msg)               // top because reverseLayout = true
            }
        }

        onDispose {
            job.cancel()
            ws.disconnect()
        }
    }

    /* ── Load history once ──────────────────────────────────────────────── */
    LaunchedEffect(Unit) {
        runCatching { ChatApi.fetchHistory(token, eventId) }
            .onSuccess { history ->
                val initial = history
                    .map { it.copy(fromMe = it.userId == myUserId) }
                    .asReversed()                  // newest last → addAll keeps order
                messages.addAll(initial)
            }
            .onFailure { it.printStackTrace() }
        loadingHistory = false
    }

    /* ── UI ──────────────────────────────────────────────────────────────── */
    Column(
        modifier = Modifier
            .fillMaxSize()
            .windowInsetsPadding(
                WindowInsets.navigationBars.union(WindowInsets.ime)
            )
    ) {
        TopAppBar(
            title = { Text(chatTitle) },
            navigationIcon = {
                IconButton(onClick = onBack) {
                    Icon(Icons.Default.ArrowBack, contentDescription = "Voltar")
                }
            }
        )

        when {
            loadingHistory -> {
                Box(Modifier.weight(1f).fillMaxWidth(), Alignment.Center) {
                    CircularProgressIndicator()
                }
            }
            messages.isEmpty() -> {
                Box(Modifier.weight(1f).fillMaxWidth(), Alignment.Center) {
                    Text("No messages yet.")
                }
            }
            else -> {
                LazyColumn(
                    modifier = Modifier
                        .weight(1f)
                        .fillMaxWidth()
                        .padding(horizontal = 8.dp, vertical = 4.dp),
                    reverseLayout = true
                ) {
                    items(messages) { msg ->        // already newest at top
                        MessageBubble(msg)
                    }
                }
            }
        }

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            OutlinedTextField(
                value = input,
                onValueChange = { input = it },
                modifier = Modifier.weight(1f),
                placeholder = { Text("Message") },
                singleLine = true,
                keyboardOptions  = KeyboardOptions(imeAction = ImeAction.Send),
                keyboardActions  = KeyboardActions(
                    onSend = {
                        scope.launch {
                            trySendMessage(token, eventId, input) { input = "" }
                        }
                    }
                )
            )
            IconButton(
                enabled = input.isNotBlank(),
                onClick = {
                    scope.launch {
                        trySendMessage(token, eventId, input) { input = "" }
                    }
                }
            ) {
                Icon(Icons.Default.Send, contentDescription = "Enviar")
            }
        }
    }
}

/* ── Send message helper ───────────────────────────────────────────────── */
private suspend fun trySendMessage(
    token:     String,
    eventId:   Int,
    text:      String,
    onSuccess: () -> Unit
) = runCatching {
    ChatApi.sendMessage(token, eventId, text)
}.onSuccess { onSuccess() }
    .onFailure { it.printStackTrace() }

/* ── Message bubble ────────────────────────────────────────────────────── */
@Composable
private fun MessageBubble(msg: Message) {
    val bubbleColor = if (msg.fromMe)
        MaterialTheme.colorScheme.primary
    else
        MaterialTheme.colorScheme.surfaceVariant

    val alignment = if (msg.fromMe) Alignment.End else Alignment.Start

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 2.dp),
        horizontalAlignment = alignment
    ) {
        Surface(
            color          = bubbleColor,
            shape          = MaterialTheme.shapes.medium,
            tonalElevation = 2.dp
        ) {
            Column(Modifier.padding(8.dp)) {
                if (!msg.fromMe) {
                    Text(
                        text  = msg.author,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                Text(text = msg.text)
            }
        }
        Text(
            text     = msg.timestamp,
            style    = MaterialTheme.typography.labelSmall,
            color    = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(top = 2.dp)
        )
    }
}
