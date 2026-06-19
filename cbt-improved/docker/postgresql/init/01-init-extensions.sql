-- PostgreSQL Initialization Script for CBT-Improved
-- This script runs automatically when the container is first created

-- Enable necessary extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Create enum types for better data integrity
CREATE TYPE exam_status AS ENUM ('draft', 'published', 'active', 'closed', 'archived');
CREATE TYPE question_type AS ENUM ('multiple_choice', 'true_false', 'essay', 'matching', 'fill_blank', 'ordering');
CREATE TYPE user_role AS ENUM ('super_admin', 'admin', 'proctor', 'teacher', 'student');
CREATE TYPE attempt_status AS ENUM ('in_progress', 'submitted', 'graded', 'reviewed', 'cancelled');
CREATE TYPE log_level AS ENUM ('info', 'warning', 'error', 'critical');

-- Grant permissions
GRANT ALL PRIVILEGES ON DATABASE cbt_improved TO cbt_user;
