package main

import (
	"bytes"
	"context"
	"crypto/md5"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

func TestMediaDigestReaderUsesWholeContentMD5(t *testing.T) {
	content := bytes.Repeat([]byte("synthetic-media-"), 1200000)
	reader := newMediaDigestReader(bytes.NewReader(content))
	if _, err := io.Copy(io.Discard, reader); err != nil {
		t.Fatal(err)
	}
	want := md5.Sum(content)
	if reader.MD5() != hex.EncodeToString(want[:]) {
		t.Fatalf("whole-object MD5 mismatch: got %s", reader.MD5())
	}
	if reader.size != int64(len(content)) {
		t.Fatalf("byte count mismatch: got %d, want %d", reader.size, len(content))
	}
	first := md5.Sum(content[:5*1024*1024])
	second := md5.Sum(content[5*1024*1024:])
	parts := append(first[:], second[:]...)
	multipartETag := md5.Sum(parts)
	if reader.MD5() == hex.EncodeToString(multipartETag[:]) {
		t.Fatal("whole-object MD5 unexpectedly matches multipart ETag")
	}
}

func TestUploadVerifiedMediaMinio(t *testing.T) {
	endpoint := os.Getenv("QIWEIDOC_MEDIA_TEST_S3_ENDPOINT")
	if endpoint == "" {
		t.Skip("set QIWEIDOC_MEDIA_TEST_S3_ENDPOINT for isolated MinIO integration")
	}
	ctx := context.Background()
	client := s3.New(s3.Options{
		BaseEndpoint: aws.String(endpoint),
		Region:       "us-east-1",
		UsePathStyle: true,
		Credentials: aws.NewCredentialsCache(credentials.NewStaticCredentialsProvider(
			"drillaccess", "drillsecret20260918", "",
		)),
	})
	bucket := fmt.Sprintf("qiweidoc-media-drill-%d", time.Now().UnixNano())
	key := "synthetic/multipart-media"
	if _, err := client.CreateBucket(ctx, &s3.CreateBucketInput{Bucket: aws.String(bucket)}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		client.DeleteObject(ctx, &s3.DeleteObjectInput{Bucket: aws.String(bucket), Key: aws.String(key)})
		client.DeleteBucket(ctx, &s3.DeleteBucketInput{Bucket: aws.String(bucket)})
	})
	content := bytes.Repeat([]byte("synthetic-media-"), 1200000)
	result, err := uploadVerifiedMedia(ctx, client, newMediaDigestReader(bytes.NewReader(content)), bucket, key)
	if err != nil {
		t.Fatal(err)
	}
	want := md5.Sum(content)
	if result.Hash != hex.EncodeToString(want[:]) || result.Size != int64(len(content)) {
		t.Fatalf("wrong verified media: hash=%s size=%d", result.Hash, result.Size)
	}
	head, err := client.HeadObject(ctx, &s3.HeadObjectInput{Bucket: aws.String(bucket), Key: aws.String(key)})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(aws.ToString(head.ETag), "-") {
		t.Fatalf("test did not exercise multipart upload: ETag=%s", aws.ToString(head.ETag))
	}
	if strings.Contains(aws.ToString(head.ETag), result.Hash) {
		t.Fatal("content MD5 must not be inferred from multipart ETag")
	}
}
