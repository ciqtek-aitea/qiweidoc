package main

import (
	"context"
	"crypto/md5"
	"encoding/hex"
	"fmt"
	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/feature/s3/manager"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/roadrunner-server/errors"
	"hash"
	"io"
)

type mediaDigestReader struct {
	source io.Reader
	digest hash.Hash
	size   int64
}

func newMediaDigestReader(source io.Reader) *mediaDigestReader {
	return &mediaDigestReader{source: source, digest: md5.New()}
}

func (reader *mediaDigestReader) Read(buffer []byte) (int, error) {
	n, err := reader.source.Read(buffer)
	if n > 0 {
		_, _ = reader.digest.Write(buffer[:n])
		reader.size += int64(n)
	}
	return n, err
}

func (reader *mediaDigestReader) MD5() string {
	return hex.EncodeToString(reader.digest.Sum(nil))
}

type FetchMediaDataRequest struct {
	CorpId     string `json:"corp_id"`
	ChatSecret string `json:"chat_secret"`
	SdkFileId  string `json:"sdk_file_id"`
	Proxy      string `json:"proxy"`
	Passwd     string `json:"passwd"`
	Timeout    int    `json:"timeout"`

	StorageEndpoint   string `json:"storage_endpoint"`
	StorageRegion     string `json:"storage_region"`
	StorageAccessKey  string `json:"storage_access_key"`
	StorageSecretKey  string `json:"storage_secret_key"`
	StorageBucketName string `json:"storage_bucket_name"`
	StorageObjectKey  string `json:"storage_object_key"`
}

type FileInfo struct {
	Hash string `json:"hash"`
	Mime string `json:"mime"`
	Size int64  `json:"size"`
}

func FetchAndStreamMediaData(input *FetchMediaDataRequest) (*FileInfo, error) {
	const Op = "plugin_wxfinance: FetchAndStreamMediaData"

	if len(input.CorpId) == 0 {
		return nil, errors.E(Op, "缺少corp_id参数")
	}
	if len(input.ChatSecret) == 0 {
		return nil, errors.E(Op, `缺少chat_secret参数`)
	}
	if len(input.SdkFileId) == 0 {
		return nil, errors.E(Op, `缺少sdk_file_id参数`)
	}
	if len(input.StorageEndpoint) == 0 {
		return nil, errors.E(Op, `缺少storage_endpoint参数`)
	}
	if len(input.StorageAccessKey) == 0 {
		return nil, errors.E(Op, `缺少storage_access_key参数`)
	}
	if len(input.StorageSecretKey) == 0 {
		return nil, errors.E(Op, `缺少storage_secret_key参数`)
	}
	if len(input.StorageBucketName) == 0 {
		return nil, errors.E(Op, `缺少storage_bucket_name参数`)
	}
	if len(input.StorageObjectKey) == 0 {
		return nil, errors.E(Op, `缺少storage_object_key参数`)
	}

	client := s3.New(s3.Options{
		BaseEndpoint: aws.String(input.StorageEndpoint),
		Region:       input.StorageRegion,
		UsePathStyle: true,
		Credentials:  aws.NewCredentialsCache(credentials.NewStaticCredentialsProvider(input.StorageAccessKey, input.StorageSecretKey, "")),
	})

	sdk, err := NewSDK()
	if err != nil {
		return nil, errors.E(Op, err)
	}
	defer sdk.Close()

	err = sdk.Init(input.CorpId, input.ChatSecret)
	if err != nil {
		return nil, errors.E(Op, err)
	}

	streamingReader := newMediaDigestReader(NewStreamingReader(sdk, input.SdkFileId, input.Proxy, input.Passwd, input.Timeout))
	return uploadVerifiedMedia(context.TODO(), client, streamingReader, input.StorageBucketName, input.StorageObjectKey)
}

func uploadVerifiedMedia(ctx context.Context, client *s3.Client, streamingReader *mediaDigestReader, bucket, key string) (*FileInfo, error) {
	uploader := manager.NewUploader(client)
	_, err := uploader.Upload(ctx, &s3.PutObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(key),
		Body:   streamingReader,
	})
	if err != nil {
		return nil, errors.E("uploadVerifiedMedia", err)
	}

	headOutput, err := client.HeadObject(ctx, &s3.HeadObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		return nil, errors.E("uploadVerifiedMedia", err)
	}

	if aws.ToInt64(headOutput.ContentLength) != streamingReader.size {
		return nil, errors.E("uploadVerifiedMedia", fmt.Errorf("上传后文件大小不一致"))
	}

	object, err := client.GetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		return nil, errors.E("uploadVerifiedMedia", err)
	}
	defer object.Body.Close()
	remoteDigest := md5.New()
	remoteSize, err := io.Copy(remoteDigest, object.Body)
	if err != nil {
		return nil, errors.E("uploadVerifiedMedia", err)
	}
	if remoteSize != streamingReader.size || hex.EncodeToString(remoteDigest.Sum(nil)) != streamingReader.MD5() {
		return nil, errors.E("uploadVerifiedMedia", fmt.Errorf("上传后文件内容校验失败"))
	}

	return &FileInfo{
		Hash: streamingReader.MD5(),
		Size: remoteSize,
		Mime: aws.ToString(headOutput.ContentType),
	}, nil
}
