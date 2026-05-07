provider "aws" {
  access_key = "test"
  secret_key = "test"
  region     = "us-east-1"

  skip_credentials_validation = true
  skip_metadata_api_check     = true
  skip_requesting_account_id  = true

  s3_use_path_style = true

  endpoints {
    s3 = "http://localhost:4566"
  }
}

resource "aws_s3_bucket" "dispatch_bucket" {
  bucket = "dispatch-bucket"

  tags = {
    Name        = "dispatch-bucket"
    Environment = "localstack"
    ManagedBy   = "terraform"
  }
}

resource "aws_s3_bucket_versioning" "dispatch_bucket_versioning" {
  bucket = aws_s3_bucket.dispatch_bucket.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_public_access_block" "dispatch_bucket_pab" {
  bucket = aws_s3_bucket.dispatch_bucket.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

output "dispatch_bucket_name" {
  value = aws_s3_bucket.dispatch_bucket.bucket
}
