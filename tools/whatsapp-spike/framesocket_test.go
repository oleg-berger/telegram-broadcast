package socket

import (
	"bytes"
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"
	waLog "go.mau.fi/whatsmeow/util/log"
)

func TestSendFramePreservesPayloadAndWritesHeaderOnce(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	payloads := [][]byte{{1, 2, 3, 4}, bytes.Repeat([]byte{0xab}, 300)}
	frames := make(chan []byte, len(payloads))
	errors := make(chan error, 1)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{OriginPatterns: []string{"web.whatsapp.com"}})
		if err != nil {
			errors <- err
			return
		}
		defer conn.CloseNow()
		for range payloads {
			kind, frame, err := conn.Read(ctx)
			if err != nil {
				errors <- err
				return
			}
			if kind != websocket.MessageBinary {
				errors <- fmt.Errorf("unexpected message type: %d", kind)
				return
			}
			frames <- frame
		}
	}))
	defer server.Close()

	client := NewFrameSocket(waLog.Noop, server.Client())
	client.URL = "ws" + strings.TrimPrefix(server.URL, "http")
	if err := client.Connect(ctx); err != nil {
		t.Fatal(err)
	}
	defer client.Close(0)
	for index, payload := range payloads {
		if err := client.SendFrame(payload); err != nil {
			t.Fatal(err)
		}
		var want []byte
		if index == 0 {
			want = append(want, WAConnHeader...)
		}
		want = append(want, byte(len(payload)>>16), byte(len(payload)>>8), byte(len(payload)))
		want = append(want, payload...)
		select {
		case got := <-frames:
			if !bytes.Equal(got, want) {
				t.Errorf("frame %d has %d bytes, want %d; frame content differs", index, len(got), len(want))
			}
		case err := <-errors:
			t.Fatal(err)
		case <-ctx.Done():
			t.Fatal(ctx.Err())
		}
	}
}
